<?php
declare(strict_types=1);

namespace AtprotoWC\Repo;

/** An in-memory repo for previewing what a sync would publish, without an account. */
final class PreviewRepo implements RepoClient
{
    /** @var array<string, array<string, mixed>> collection/rkey => record */
    public array $records = [];

    public function __construct(private string $did = 'did:plc:previewaaaaaaaaaaaaaaaaa')
    {
    }

    public function did(): string
    {
        return $this->did;
    }

    public function uploadBlob(string $bytes, string $mimeType): array
    {
        // A placeholder CID: previews don't upload anything.
        return ['$type' => 'blob', 'ref' => ['$link' => 'bafkreialrlq76mkl7jukpk3j2eadrtlthf4wxnaleefbp63nwkwpvsuf3e'], 'mimeType' => $mimeType, 'size' => strlen($bytes)];
    }

    public function listRecords(string $collection): array
    {
        return [];
    }

    public function applyWrites(array $writes): array
    {
        foreach ($writes as $w) {
            if ($w['action'] === 'delete') {
                unset($this->records[$w['collection'] . '/' . $w['rkey']]);
            } else {
                $this->records[$w['collection'] . '/' . $w['rkey']] = $w['value'];
            }
        }
        return array_fill(0, count($writes), null);
    }
}
