<?php
declare(strict_types=1);

namespace AtprotoWC;

use AtprotoWC\Repo\RepoClient;

/**
 * Reconciliation: build the records the catalogue should produce, compare
 * their hashes with what was last published, and write only the difference
 * in atomic batches. Running it twice in a row writes nothing.
 */
final class Sync
{
    private const BATCH = 200;
    private const MAX_IMAGES = 8;
    private const ORDER = [Records::STOREFRONT => 0, Records::PRODUCT => 1, Records::VARIANT => 2];

    public function __construct(private Catalogue $catalogue, private SyncState $state)
    {
    }

    /**
     * @return array{created: int, updated: int, deleted: int, unchanged: int, skipped: list<array{key: string, error: string}>, untracked: int}
     */
    public function run(RepoClient $repo, bool $full = false, bool $retried = false): array
    {
        $did = $repo->did();
        $rows = $this->state->rows($did);
        // Compare with the repo itself on a full run, and always on the first.
        $full = $full || !$rows;

        $missing = [];
        $remoteSelf = false;
        $untracked = 0;
        if ($full) {
            $tracked = [];
            foreach ($rows as $r) {
                $tracked[$r['collection'] . '/' . $r['rkey']] = true;
            }
            $remote = [];
            foreach (array_keys(self::ORDER) as $collection) {
                foreach ($repo->listRecords($collection) as $rec) {
                    $rkey = substr($rec['uri'], strrpos($rec['uri'], '/') + 1);
                    $remote[$collection . '/' . $rkey] = true;
                    if ($collection === Records::STOREFRONT && $rkey === 'self') {
                        $remoteSelf = true;
                    } elseif (!isset($tracked[$collection . '/' . $rkey])) {
                        $untracked++;
                    }
                }
            }
            foreach ($rows as $key => $r) {
                if (!isset($remote[$r['collection'] . '/' . $r['rkey']])) {
                    $missing[$key] = true;
                }
            }
        }

        [$desired, $skipped] = $this->desired($repo, $rows);

        $upserts = [];
        $unchanged = 0;
        foreach ($desired as $key => $d) {
            $row = $rows[$key] ?? null;
            $exists = $row !== null ? !isset($missing[$key]) : ($key === 'storefront' && $remoteSelf);
            if ($row !== null && $exists && $row['hash'] === $d['hash']) {
                $unchanged++;
                continue;
            }
            $upserts[] = ['action' => $exists ? 'update' : 'create', 'collection' => $d['collection'], 'rkey' => $d['rkey'], 'value' => $d['record'], 'key' => $key, 'hash' => $d['hash']];
        }
        usort($upserts, static fn ($a, $b) => self::ORDER[$a['collection']] <=> self::ORDER[$b['collection']]);

        $deletes = [];
        foreach ($rows as $key => $r) {
            if (isset($desired[$key]) || isset($skipped[$key])) {
                continue;
            }
            if (isset($missing[$key])) {
                $this->state->delete($did, $key); // already gone from the repo
                continue;
            }
            $deletes[] = ['action' => 'delete', 'collection' => $r['collection'], 'rkey' => $r['rkey'], 'key' => $key];
        }
        // Variants before the products they point at.
        usort($deletes, static fn ($a, $b) => self::ORDER[$b['collection']] <=> self::ORDER[$a['collection']]);

        $result = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => $unchanged, 'skipped' => array_values($skipped), 'untracked' => $untracked];
        foreach (array_chunk([...$deletes, ...$upserts], self::BATCH) as $batch) {
            try {
                $cids = $repo->applyWrites(array_map(static fn ($w) => array_diff_key($w, ['key' => 1, 'hash' => 1]), $batch));
            } catch (\Throwable $e) {
                // Our view of the repo, or of its blobs, is out of date (edited
                // elsewhere, or an uploaded blob was cleaned up before use).
                // Forget cached blobs and retry once against a fresh listing.
                if ($retried) {
                    throw $e;
                }
                $this->state->forgetBlobs($did);
                return $this->run($repo, true, true);
            }
            foreach ($batch as $i => $w) {
                if ($w['action'] === 'delete') {
                    $this->state->delete($did, $w['key']);
                    $result['deleted']++;
                } else {
                    $this->state->upsert($did, $w['key'], $w['collection'], $w['rkey'], $w['hash'], $cids[$i] ?? null);
                    $result[$w['action'] === 'create' ? 'created' : 'updated']++;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @return array{0: array<string, array{collection: string, rkey: string, record: array<string, mixed>, hash: string}>, 1: array<string, array{key: string, error: string}>}
     */
    private function desired(RepoClient $repo, array $rows): array
    {
        $did = $repo->did();
        $store = $this->catalogue->store();
        $desired = [];
        $skipped = [];
        $add = static function (string $key, string $collection, string $rkey, array $record) use (&$desired): void {
            $desired[$key] = ['collection' => $collection, 'rkey' => $rkey, 'record' => $record, 'hash' => Records::hash($record)];
        };

        $logo = $store['logo_image'] ? $this->blob($repo, $store['logo_image']) : null;
        $add('storefront', Records::STOREFRONT, 'self', Records::storefront(['did' => $did] + $store, $logo));

        foreach ($this->catalogue->products() as $p) {
            $productKey = 'product:' . $p['id'];
            $variants = [];
            foreach ($p['variants'] as $v) {
                $variants[$v['key']] = $v; // duplicate SKUs: publish the first
            }
            if (!$variants) {
                $skipped[$productKey] = ['key' => $productKey, 'error' => 'no price to publish'];
                continue;
            }
            $rkey = $rows[$productKey]['rkey'] ?? Tid::next();
            $productUri = "at://{$did}/" . Records::PRODUCT . "/{$rkey}";
            $blobs = [];
            foreach (array_slice($p['image_ids'], 0, self::MAX_IMAGES) as $imageId) {
                $blob = $this->blob($repo, (int) $imageId);
                if ($blob) {
                    $blobs[] = $blob;
                }
            }
            $add($productKey, Records::PRODUCT, $rkey, Records::product($p, $blobs));
            foreach ($variants as $vKey => $v) {
                $key = "variant:{$p['id']}:{$vKey}";
                $record = Records::variant($v, $p, $productUri, $store['currency']);
                if ($record === null) {
                    $skipped[$key] = ['key' => $key, 'error' => 'no price'];
                    continue;
                }
                $add($key, Records::VARIANT, $rows[$key]['rkey'] ?? Tid::next(), $record);
            }
        }
        return [$desired, $skipped];
    }

    /** Uploads an image once per distinct file. @return array<string, mixed>|null */
    private function blob(RepoClient $repo, int $attachmentId): ?array
    {
        $image = $this->catalogue->image($attachmentId);
        if ($image === null) {
            return null;
        }
        $did = $repo->did();
        $cached = $this->state->blob($did, $image['hash']);
        if ($cached !== null) {
            return $cached;
        }
        $blob = $repo->uploadBlob($image['bytes'], $image['mime']);
        $this->state->saveBlob($did, $image['hash'], $blob);
        return $blob;
    }
}
