<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\Identity;
use AtprotoWC\OAuth\ClientConfig;
use AtprotoWC\Records;

/**
 * WooCommerce → Settings → AT Protocol. Connect the shop's account, use the
 * shop's domain as its handle, choose what publishes, and see what happened.
 *
 * WooCommerce wraps the tab in its own form, so the account actions are
 * buttons with formaction pointing at admin-post.php, each with its own nonce.
 */
final class SettingsTab
{
    private const TAB = 'atproto';
    private const NONCE = 'atpwc_action';

    public static function register(): void
    {
        add_filter('woocommerce_settings_tabs_array', static fn (array $tabs) => $tabs + [self::TAB => __('AT Protocol', 'atproto-for-woocommerce')], 50);
        add_action('woocommerce_settings_' . self::TAB, [self::class, 'render']);
        add_action('woocommerce_update_options_' . self::TAB, [self::class, 'save']);
        foreach (['connect', 'disconnect', 'sync', 'use_domain'] as $action) {
            add_action('admin_post_atpwc_' . $action, [self::class, $action]);
        }
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=' . self::TAB);
    }

    /** @return list<array<string, mixed>> */
    private static function fields(): array
    {
        $categories = [];
        foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]) ?: [] as $term) {
            $categories[$term->term_id] = $term->name;
        }
        return [
            ['type' => 'title', 'title' => __('Publishing', 'atproto-for-woocommerce'), 'id' => 'atpwc_publishing',
                'desc' => __('Only published products that customers can see are sent: name, description, images, brand, tags, category, price, and whether each option is in stock. Drafts, hidden or password-protected products, stock counts and customer data never leave your shop. Unpublishing or deleting a product removes it.', 'atproto-for-woocommerce')],
            ['type' => 'checkbox', 'id' => Plugin::OPT_ENABLED, 'title' => __('Publish products', 'atproto-for-woocommerce'), 'desc' => __('Keep my AT Protocol account in sync with the shop', 'atproto-for-woocommerce'), 'default' => 'no'],
            ['type' => 'multiselect', 'id' => Plugin::OPT_EXCLUDE, 'title' => __('Leave out categories', 'atproto-for-woocommerce'), 'options' => $categories, 'class' => 'wc-enhanced-select', 'css' => 'min-width: 350px', 'default' => []],
            ['type' => 'text', 'id' => Plugin::OPT_DIRECTORY, 'title' => __('Directory', 'atproto-for-woocommerce'), 'desc' => __('Only used for the "see your shop" link. Any directory that reads the network will find your products.', 'atproto-for-woocommerce'), 'default' => 'https://atmarket.co'],
            ['type' => 'sectionend', 'id' => 'atpwc_publishing'],
        ];
    }

    public static function save(): void
    {
        \WC_Admin_Settings::save_fields(self::fields());
        if (Plugin::enabled()) {
            Plugin::syncSoon('settings');
        }
    }

    private static function button(string $action, string $label, string $class = 'button', string $confirm = ''): string
    {
        return sprintf(
            '<button type="submit" class="%s" formaction="%s" formnovalidate%s>%s</button>',
            esc_attr($class),
            esc_url(admin_url('admin-post.php?action=atpwc_' . $action)),
            $confirm ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '',
            esc_html($label),
        );
    }

    public static function render(): void
    {
        $session = (new WpStorage())->getSession();
        $site = home_url();
        $host = (string) parse_url($site, PHP_URL_HOST);
        $atRoot = trim((string) parse_url($site, PHP_URL_PATH), '/') === '';
        $loopback = ClientConfig::isLoopback($site);
        $https = str_starts_with($site, 'https://');
        $error = isset($_GET['atpwc_error']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['atpwc_error']))) : null;
        $notice = sanitize_key($_GET['atpwc'] ?? '');

        echo '<h2>' . esc_html__('AT Protocol', 'atproto-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Publish your products to your own AT Protocol account (the network behind Bluesky), so open directories can list them. Your shop stays the source of truth, and buyers still check out here.', 'atproto-for-woocommerce') . '</p>';
        wp_nonce_field(self::NONCE, 'atpwc_nonce');

        $messages = [
            'connected' => __('Connected. Your first sync has been queued.', 'atproto-for-woocommerce'),
            'disconnected' => __('Disconnected. Records already published stay on the account until you delete them there.', 'atproto-for-woocommerce'),
            'syncing' => __('Sync queued. It runs in the background within a minute or so.', 'atproto-for-woocommerce'),
            'handle' => __('Handle updated.', 'atproto-for-woocommerce'),
        ];
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
        if ($error) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
        }
        if (!$loopback && !$https) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Your site address must use https to connect.', 'atproto-for-woocommerce') . '</p></div>';
        }

        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Account', 'atproto-for-woocommerce') . '</th><td>';
        if ($session) {
            printf('<p><strong>%s</strong><br><code>%s</code></p>', esc_html($session->handle ? '@' . $session->handle : $session->did), esc_html($session->did));
            echo '<p>' . self::button('sync', __('Sync now', 'atproto-for-woocommerce'), 'button button-primary') . ' ';
            echo self::button('disconnect', __('Disconnect', 'atproto-for-woocommerce'), 'button', __('Disconnect this account? Published records stay on it.', 'atproto-for-woocommerce')) . '</p>';
            $directory = rtrim((string) get_option(Plugin::OPT_DIRECTORY, 'https://atmarket.co'), '/');
            if ($session->handle && $directory) {
                printf('<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url($directory . '/@' . $session->handle), esc_html(sprintf(__('See your shop on %s', 'atproto-for-woocommerce'), parse_url($directory, PHP_URL_HOST))));
            }
        } else {
            echo '<p><input type="text" name="atpwc_handle" class="regular-text" placeholder="yourshop.bsky.social" autocomplete="off"> ';
            echo self::button('connect', __('Connect', 'atproto-for-woocommerce'), 'button button-primary') . '</p>';
            echo '<p class="description">' . esc_html__("Sign in with the account your shop should publish as. Don't have one? Create a free account at bsky.app, then connect it here.", 'atproto-for-woocommerce') . '</p>';
            if ($loopback) {
                echo '<p class="description">' . esc_html__('Running on localhost: sign-in works, but sessions are short-lived.', 'atproto-for-woocommerce') . '</p>';
            }
        }
        echo '</td></tr>';

        // The domain-as-handle step: what makes the shop "verified" in directories.
        if ($session && $https && $atRoot) {
            echo '<tr><th scope="row">' . esc_html__('Verified handle', 'atproto-for-woocommerce') . '</th><td>';
            if ($session->handle === strtolower($host)) {
                printf('<p>✓ %s</p>', esc_html(sprintf(__('Your handle is @%s, your shop\'s own domain. Directories show your shop as verified.', 'atproto-for-woocommerce'), $host)));
            } elseif ($session->hasScope('identity:handle')) {
                echo '<p>' . esc_html(sprintf(__('Use %s as your handle. Directories treat a handle on your own domain as proof the shop is yours, and show it as verified.', 'atproto-for-woocommerce'), '@' . $host)) . '</p>';
                echo '<p>' . self::button('use_domain', sprintf(__('Use @%s', 'atproto-for-woocommerce'), $host)) . '</p>';
            } else {
                echo '<p>' . esc_html__('Reconnect to allow changing the handle.', 'atproto-for-woocommerce') . '</p>';
            }
            echo '</td></tr>';
        }

        if ($session) {
            $counts = (new WpSyncState())->counts($session->did);
            printf(
                '<tr><th scope="row">%s</th><td>%s</td></tr>',
                esc_html__('Published', 'atproto-for-woocommerce'),
                esc_html(sprintf(__('%1$d products, %2$d options', 'atproto-for-woocommerce'), $counts[Records::PRODUCT] ?? 0, $counts[Records::VARIANT] ?? 0)),
            );
        }
        echo '</tbody></table>';

        woocommerce_admin_fields(self::fields());

        $runs = get_option(Plugin::OPT_RUNS, []);
        if ($runs) {
            echo '<h2>' . esc_html__('Recent syncs', 'atproto-for-woocommerce') . '</h2><table class="widefat striped" style="max-width: 900px"><thead><tr>';
            foreach ([__('When', 'atproto-for-woocommerce'), __('Trigger', 'atproto-for-woocommerce'), __('Result', 'atproto-for-woocommerce'), __('Created', 'atproto-for-woocommerce'), __('Updated', 'atproto-for-woocommerce'), __('Deleted', 'atproto-for-woocommerce'), __('Notes', 'atproto-for-woocommerce')] as $h) {
                echo '<th>' . esc_html($h) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($runs as $run) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
                    esc_html(sprintf(__('%s ago', 'atproto-for-woocommerce'), human_time_diff($run['time']))),
                    esc_html($run['trigger']),
                    $run['status'] === 'completed' ? '✓' : '<strong style="color:#b32d2e">' . esc_html__('Failed', 'atproto-for-woocommerce') . '</strong>',
                    $run['created'],
                    $run['updated'],
                    $run['deleted'],
                    esc_html($run['message']),
                );
            }
            echo '</tbody></table>';
        }
    }

    private static function guard(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer(self::NONCE, 'atpwc_nonce')) {
            wp_die(esc_html__('Not allowed.', 'atproto-for-woocommerce'), 403);
        }
    }

    /** @param array<string, string> $args */
    private static function back(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, self::url()));
        exit;
    }

    public static function connect(): void
    {
        self::guard();
        $handle = sanitize_text_field(wp_unslash($_POST['atpwc_handle'] ?? ''));
        if ($handle === '') {
            self::back(['atpwc_error' => rawurlencode(__('Enter your handle, for example yourshop.bsky.social.', 'atproto-for-woocommerce'))]);
        }
        try {
            // External: the account's own sign-in server.
            wp_redirect(Plugin::oauth()->authorize($handle));
            exit;
        } catch (\Throwable $e) {
            self::back(['atpwc_error' => rawurlencode($e->getMessage())]);
        }
    }

    public static function disconnect(): void
    {
        self::guard();
        $storage = new WpStorage();
        if ($session = $storage->getSession()) {
            Plugin::oauth()->revoke($session);
        }
        $storage->putSession(null);
        update_option(Plugin::OPT_ENABLED, 'no');
        update_option(Plugin::OPT_SERVE_DID, 'no');
        self::back(['atpwc' => 'disconnected']);
    }

    public static function sync(): void
    {
        self::guard();
        Plugin::syncSoon('manual');
        self::back(['atpwc' => 'syncing']);
    }

    /** Serves /.well-known/atproto-did, then asks the PDS to switch the handle to the shop's domain. */
    public static function use_domain(): void
    {
        self::guard();
        $host = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        update_option(Plugin::OPT_SERVE_DID, 'yes');
        try {
            $repo = Plugin::repo();
            // The PDS checks the domain resolves to this account before accepting it.
            $repo->updateHandle($host);
            $storage = new WpStorage();
            $session = $storage->getSession();
            if ($session) {
                $session->handle = (new Identity(Plugin::http()))->resolveHandle($host) === $session->did ? $host : $session->handle;
                $storage->putSession($session);
            }
            Plugin::syncSoon('handle');
            self::back(['atpwc' => 'handle']);
        } catch (\Throwable $e) {
            self::back(['atpwc_error' => rawurlencode(sprintf(__("Couldn't change the handle: %s", 'atproto-for-woocommerce'), $e->getMessage()))]);
        }
    }
}
