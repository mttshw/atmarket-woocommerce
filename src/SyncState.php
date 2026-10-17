<?php
declare(strict_types=1);

namespace AtprotoWC;

/** What has been published, so each sync writes only the difference. */
interface SyncState
{
    /** @return array<string, array{local_key: string, collection: string, rkey: string, hash: string, cid: ?string}> keyed by local_key */
    public function rows(string $did): array;

    public function upsert(string $did, string $localKey, string $collection, string $rkey, string $hash, ?string $cid): void;

    public function delete(string $did, string $localKey): void;

    /** @return array<string, mixed>|null */
    public function blob(string $did, string $fileHash): ?array;

    /** @param array<string, mixed> $blob */
    public function saveBlob(string $did, string $fileHash, array $blob): void;

    public function forgetBlobs(string $did): void;
}
