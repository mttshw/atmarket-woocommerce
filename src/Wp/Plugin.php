<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\Crypto\EcKey;
use AtprotoWC\Http\WpHttp;
use AtprotoWC\Identity;
use AtprotoWC\OAuth\ClientConfig;
use AtprotoWC\OAuth\OAuthClient;
use AtprotoWC\Repo\XrpcRepo;
use AtprotoWC\Sync;

/**
 * Wiring, triggers and the run log.
 *
 * When syncs run: product, variation, stock and store-setting changes
 * schedule one sync 30 s out (rescheduling on each change, so a burst of
 * edits is one sync), plus an hourly full sync that also checks the repo.
 * All of it goes through Action Scheduler, so nothing slows down saving.
 */
final class Plugin
{
    public const OPT_ENABLED = 'atpwc_enabled';
    public const OPT_EXCLUDE = 'atpwc_exclude_categories';
    public const OPT_DIRECTORY = 'atpwc_directory_url';
    public const OPT_SERVE_DID = 'atpwc_serve_did';
    public const OPT_CLIENT_KEY = 'atpwc_client_key';
    public const OPT_RUNS = 'atpwc_runs';
    public const HOOK = 'atpwc_sync';
    public const GROUP = 'atproto-for-woocommerce';
    private const DEBOUNCE = 30;
    private const LOCK = 'atpwc_sync_lock';

    public static function boot(): void
    {
        Routes::register();
        if (is_admin()) {
            SettingsTab::register();
        }
        if (defined('WP_CLI') && WP_CLI) {
            Cli::register();
        }
        add_action(self::HOOK, [self::class, 'runScheduled'], 10, 2);
        add_action('init', [self::class, 'ensureHourly']);

        $change = static fn () => self::schedule();
        foreach ([
            'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product', 'woocommerce_trash_product',
            'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_delete_product_variation',
            'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status',
            'update_option_blogname', 'update_option_blogdescription', 'update_option_woocommerce_currency',
            'woocommerce_update_options_shipping', 'untrashed_post',
        ] as $hook) {
            add_action($hook, $change);
        }
    }

    public static function activate(): void
    {
        WpSyncState::createTables();
        add_option(self::OPT_ENABLED, 'no');
        add_option(self::OPT_DIRECTORY, 'https://atmarket.co');
    }

    public static function deactivate(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [], self::GROUP);
        }
    }

    public static function enabled(): bool
    {
        return get_option(self::OPT_ENABLED) === 'yes' && (new WpStorage())->getSession() !== null;
    }

    /** Schedules a sync 30 s out, replacing any pending edit sync. */
    public static function schedule(string $trigger = 'edit'): void
    {
        if (!self::enabled() || !function_exists('as_schedule_single_action')) {
            return;
        }
        $args = ['trigger' => $trigger, 'full' => false];
        as_unschedule_action(self::HOOK, $args, self::GROUP);
        as_schedule_single_action(time() + self::DEBOUNCE, self::HOOK, $args, self::GROUP);
    }

    /** Queues a full sync to run as soon as the queue picks it up. */
    public static function syncSoon(string $trigger): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, ['trigger' => $trigger, 'full' => true], self::GROUP);
        }
    }

    public static function ensureHourly(): void
    {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }
        $args = ['trigger' => 'hourly', 'full' => true];
        if (!as_has_scheduled_action(self::HOOK, $args, self::GROUP)) {
            as_schedule_recurring_action(time() + 300, HOUR_IN_SECONDS, self::HOOK, $args, self::GROUP);
        }
    }

    public static function runScheduled(string $trigger = 'edit', bool $full = false): void
    {
        if (!self::enabled()) {
            return;
        }
        // One sync at a time. If one is running, try again shortly.
        if (get_transient(self::LOCK)) {
            as_schedule_single_action(time() + 60, self::HOOK, ['trigger' => $trigger, 'full' => $full], self::GROUP);
            return;
        }
        set_transient(self::LOCK, 1, 10 * MINUTE_IN_SECONDS);
        try {
            self::syncNow($trigger, $full);
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /** Runs a sync in this request and records the outcome. */
    public static function syncNow(string $trigger, bool $full): void
    {
        $started = time();
        try {
            $res = (new Sync(self::catalogue(), new WpSyncState()))->run(self::repo(), $full);
            $notes = [];
            if ($res['skipped']) {
                $notes[] = count($res['skipped']) . ' not published: ' . implode('; ', array_map(static fn ($s) => "{$s['key']} ({$s['error']})", array_slice($res['skipped'], 0, 5)));
            }
            if ($res['untracked']) {
                $notes[] = "{$res['untracked']} records on the account weren't written by this shop and were left alone";
            }
            self::log($trigger, $started, 'completed', $res, implode('. ', $notes));
        } catch (\Throwable $e) {
            self::log($trigger, $started, 'failed', null, $e->getMessage());
        }
    }

    /** @param array<string, mixed>|null $res */
    private static function log(string $trigger, int $started, string $status, ?array $res, string $message): void
    {
        $runs = get_option(self::OPT_RUNS, []);
        array_unshift($runs, [
            'time' => $started,
            'trigger' => $trigger,
            'status' => $status,
            'created' => $res['created'] ?? 0,
            'updated' => $res['updated'] ?? 0,
            'deleted' => $res['deleted'] ?? 0,
            'message' => $message,
        ]);
        update_option(self::OPT_RUNS, array_slice($runs, 0, 20), false);
    }

    public static function catalogue(): WcCatalogue
    {
        return new WcCatalogue(array_map('intval', (array) get_option(self::OPT_EXCLUDE, [])));
    }

    public static function http(): WpHttp
    {
        return new WpHttp();
    }

    public static function config(): ClientConfig
    {
        $site = home_url();
        $key = null;
        if (!ClientConfig::isLoopback($site)) {
            $saved = Secrets::getOption(self::OPT_CLIENT_KEY);
            if ($saved === null) {
                $key = EcKey::generate();
                Secrets::putOption(self::OPT_CLIENT_KEY, ['pem' => $key->pem()]);
            } else {
                $key = EcKey::fromPem($saved['pem']);
            }
        }
        return ClientConfig::forSite($site, wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) ?: 'WooCommerce shop', $key);
    }

    public static function oauth(): OAuthClient
    {
        return new OAuthClient(self::http(), new Identity(self::http()), self::config(), new WpStorage());
    }

    public static function repo(): XrpcRepo
    {
        return new XrpcRepo(self::http(), self::oauth(), new WpStorage());
    }
}
