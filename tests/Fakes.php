<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Catalogue;
use AtprotoWC\Repo\RepoClient;
use AtprotoWC\SyncState;

/** An in-memory repo with a PDS's applyWrites rules: atomic, create fails if it exists, update/delete fail if it doesn't. */
final class FakeRepo implements RepoClient
{
    /** @var array<string, array{cid: string, value: array<string, mixed>}> */
    public array $records = [];
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];
    public int $uploads = 0;
    public bool $failNextWrite = false;
    private int $n = 0;

    public function __construct(private string $did = 'did:plc:fakeshopaaaaaaaaaaaaaaaa')
    {
    }

    public function did(): string
    {
        return $this->did;
    }

    public function uploadBlob(string $bytes, string $mimeType): array
    {
        $this->uploads++;
        return ['$type' => 'blob', 'ref' => ['$link' => 'bafkreialrlq76mkl7jukpk3j2eadrtlthf4wxnaleefbp63nwkwpvsuf3e'], 'mimeType' => $mimeType, 'size' => strlen($bytes)];
    }

    public function listRecords(string $collection): array
    {
        $out = [];
        foreach ($this->records as $k => $r) {
            if (str_starts_with($k, $collection . '/')) {
                $out[] = ['uri' => "at://{$this->did}/{$k}", 'cid' => $r['cid'], 'value' => $r['value']];
            }
        }
        return $out;
    }

    public function applyWrites(array $writes): array
    {
        if ($this->failNextWrite) {
            $this->failNextWrite = false;
            throw new \RuntimeException('InvalidRequest: Could not find blob');
        }
        $next = $this->records;
        $cids = [];
        foreach ($writes as $w) {
            $k = $w['collection'] . '/' . $w['rkey'];
            if ($w['action'] === 'delete') {
                if (!isset($next[$k])) {
                    throw new \RuntimeException("delete: no record {$k}");
                }
                unset($next[$k]);
                $cids[] = null;
                continue;
            }
            if ($w['action'] === 'create' && isset($next[$k])) {
                throw new \RuntimeException("create: {$k} exists");
            }
            if ($w['action'] === 'update' && !isset($next[$k])) {
                throw new \RuntimeException("update: no record {$k}");
            }
            $next[$k] = ['cid' => 'bafyreicid' . (++$this->n), 'value' => $w['value']];
            $cids[] = $next[$k]['cid'];
        }
        $this->records = $next;
        $this->batches[] = $writes;
        return $cids;
    }

    /** @return list<array<string, mixed>> writes since the last call */
    public function takeWrites(): array
    {
        $all = array_merge(...$this->batches ?: [[]]);
        $this->batches = [];
        return $all;
    }

    /** @return list<array<string, mixed>> */
    public function get(string $collection): array
    {
        return array_map(static fn ($r) => $r['value'], array_values(array_filter($this->records, static fn ($k) => str_starts_with($k, $collection . '/'), ARRAY_FILTER_USE_KEY)));
    }
}

final class MemorySyncState implements SyncState
{
    public array $rows = [];
    public array $blobs = [];

    public function rows(string $did): array
    {
        return $this->rows[$did] ?? [];
    }

    public function upsert(string $did, string $localKey, string $collection, string $rkey, string $hash, ?string $cid): void
    {
        $this->rows[$did][$localKey] = ['local_key' => $localKey, 'collection' => $collection, 'rkey' => $rkey, 'hash' => $hash, 'cid' => $cid];
    }

    public function delete(string $did, string $localKey): void
    {
        unset($this->rows[$did][$localKey]);
    }

    public function blob(string $did, string $fileHash): ?array
    {
        return $this->blobs[$did][$fileHash] ?? null;
    }

    public function saveBlob(string $did, string $fileHash, array $blob): void
    {
        $this->blobs[$did][$fileHash] = $blob;
    }

    public function forgetBlobs(string $did): void
    {
        unset($this->blobs[$did]);
    }
}

final class ArrayCatalogue implements Catalogue
{
    public array $store = ['name' => 'Southsea Prints', 'description' => 'Riso prints', 'currency' => 'GBP', 'url' => 'https://shop.example.com', 'zones' => [['name' => 'United Kingdom', 'countries' => ['GB']]], 'logo_image' => null];
    /** @var array<int, array<string, mixed>> */
    public array $products = [];
    /** @var array<int, string> attachment id => file contents */
    public array $files = [];

    public function store(): array
    {
        return $this->store;
    }

    public function products(): iterable
    {
        return array_values($this->products);
    }

    public function image(int $attachmentId): ?array
    {
        $bytes = $this->files[$attachmentId] ?? null;
        return $bytes === null ? null : ['bytes' => $bytes, 'mime' => 'image/jpeg', 'hash' => hash('sha256', $bytes)];
    }

    public static function simple(int $id, array $o = []): array
    {
        return array_replace([
            'id' => $id,
            'name' => 'Harbour at dusk',
            'permalink' => "https://shop.example.com/product/harbour-{$id}/",
            'description' => '<p>A two-colour <strong>risograph</strong> print.</p><p>A3 &amp; A4.</p>',
            'short_description' => '',
            'brand' => null,
            'tags' => ['print', 'riso'],
            'categories' => ['Prints'],
            'is_digital' => false,
            'created' => '2026-09-01 10:00:00',
            'modified' => '2026-09-02 11:30:00',
            'image_ids' => [],
            'variants' => ['sku:HAD-' . $id => ['key' => 'sku:HAD-' . $id, 'sku' => 'HAD-' . $id, 'title' => null, 'price' => '25.00', 'in_stock' => true, 'purchasable' => true, 'options' => []]],
        ], $o);
    }
}
