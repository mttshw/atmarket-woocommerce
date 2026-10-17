<?php
declare(strict_types=1);

namespace AtprotoWC\Repo;

/** The repo calls the publisher makes. An interface so sync can run against an in-memory repo in tests. */
interface RepoClient
{
    public function did(): string;

    /** @return array<string, mixed> blob ref as JSON ({$type: blob, ref: {$link}, mimeType, size}) */
    public function uploadBlob(string $bytes, string $mimeType): array;

    /** @return list<array{uri: string, cid: string, value: array<string, mixed>}> every record in the collection */
    public function listRecords(string $collection): array;

    /**
     * Atomic: all writes apply or none do.
     * @param list<array{action: string, collection: string, rkey: string, value?: array<string, mixed>}> $writes
     * @return list<?string> new CID per create/update, null for deletes
     */
    public function applyWrites(array $writes): array;
}
