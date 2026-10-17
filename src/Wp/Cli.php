<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\Repo\PreviewRepo;
use AtprotoWC\Sync;

/**
 * wp atproto preview   Print the records a sync would publish, as JSON. No account needed.
 * wp atproto sync      Sync now, in this process. --full also checks the repo.
 * wp atproto status    Connected account and recent syncs.
 */
final class Cli
{
    public static function register(): void
    {
        \WP_CLI::add_command('atproto preview', [self::class, 'preview']);
        \WP_CLI::add_command('atproto sync', [self::class, 'sync']);
        \WP_CLI::add_command('atproto status', [self::class, 'status']);
    }

    public static function preview(): void
    {
        $repo = new PreviewRepo((new WpStorage())->getSession()?->did ?? 'did:plc:previewaaaaaaaaaaaaaaaaa');
        $state = new class implements \AtprotoWC\SyncState {
            private array $rows = [];
            public function rows(string $did): array { return $this->rows; }
            public function upsert(string $did, string $k, string $c, string $r, string $h, ?string $cid): void { $this->rows[$k] = ['local_key' => $k, 'collection' => $c, 'rkey' => $r, 'hash' => $h, 'cid' => $cid]; }
            public function delete(string $did, string $k): void { unset($this->rows[$k]); }
            public function blob(string $did, string $hash): ?array { return null; }
            public function saveBlob(string $did, string $hash, array $blob): void {}
            public function forgetBlobs(string $did): void {}
        };
        $res = (new Sync(Plugin::catalogue(), $state))->run($repo);
        \WP_CLI::line(wp_json_encode(['did' => $repo->did(), 'records' => $repo->records, 'skipped' => $res['skipped']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int, string> $args @param array<string, mixed> $assoc */
    public static function sync(array $args, array $assoc): void
    {
        if ((new WpStorage())->getSession() === null) {
            \WP_CLI::error('No account is connected. Connect one in WooCommerce → Settings → AT Protocol.');
        }
        Plugin::syncNow('cli', (bool) ($assoc['full'] ?? false));
        $run = get_option(Plugin::OPT_RUNS, [])[0] ?? null;
        $line = sprintf('%s: %d created, %d updated, %d deleted. %s', $run['status'], $run['created'], $run['updated'], $run['deleted'], $run['message']);
        $run['status'] === 'completed' ? \WP_CLI::success($line) : \WP_CLI::error($line);
    }

    public static function status(): void
    {
        $s = (new WpStorage())->getSession();
        \WP_CLI::line($s ? "Connected: {$s->did}" . ($s->handle ? " (@{$s->handle})" : '') : 'Not connected');
        \WP_CLI::line('Publishing: ' . get_option(Plugin::OPT_ENABLED, 'no'));
        foreach (array_slice(get_option(Plugin::OPT_RUNS, []), 0, 5) as $r) {
            \WP_CLI::line(sprintf('%s  %-9s %-9s +%d ~%d -%d %s', gmdate('Y-m-d H:i', $r['time']), $r['trigger'], $r['status'], $r['created'], $r['updated'], $r['deleted'], $r['message']));
        }
    }
}
