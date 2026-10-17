<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\SyncState;

/** Published-record and uploaded-blob bookkeeping in two small tables. */
final class WpSyncState implements SyncState
{
    public static function records(): string
    {
        return $GLOBALS['wpdb']->prefix . 'atpwc_records';
    }

    public static function blobs(): string
    {
        return $GLOBALS['wpdb']->prefix . 'atpwc_blobs';
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::records() . " (
            did VARCHAR(190) NOT NULL,
            local_key VARCHAR(190) NOT NULL,
            collection VARCHAR(100) NOT NULL,
            rkey VARCHAR(64) NOT NULL,
            hash CHAR(64) NOT NULL,
            cid VARCHAR(100) NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (did, local_key)
        ) {$charset}");
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . self::blobs() . " (
            did VARCHAR(190) NOT NULL,
            file_hash CHAR(64) NOT NULL,
            blob_ref TEXT NOT NULL,
            uploaded_at DATETIME NOT NULL,
            PRIMARY KEY (did, file_hash)
        ) {$charset}");
    }

    public static function dropTables(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::records());
        $wpdb->query('DROP TABLE IF EXISTS ' . self::blobs());
    }

    public function rows(string $did): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT local_key, collection, rkey, hash, cid FROM ' . self::records() . ' WHERE did = %s', $did), ARRAY_A) as $row) {
            $out[$row['local_key']] = $row;
        }
        return $out;
    }

    public function upsert(string $did, string $localKey, string $collection, string $rkey, string $hash, ?string $cid): void
    {
        global $wpdb;
        $wpdb->replace(self::records(), [
            'did' => $did, 'local_key' => $localKey, 'collection' => $collection, 'rkey' => $rkey,
            'hash' => $hash, 'cid' => $cid, 'synced_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $did, string $localKey): void
    {
        $GLOBALS['wpdb']->delete(self::records(), ['did' => $did, 'local_key' => $localKey]);
    }

    public function blob(string $did, string $fileHash): ?array
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare('SELECT blob_ref FROM ' . self::blobs() . ' WHERE did = %s AND file_hash = %s', $did, $fileHash));
        $blob = is_string($json) ? json_decode($json, true) : null;
        return is_array($blob) ? $blob : null;
    }

    public function saveBlob(string $did, string $fileHash, array $blob): void
    {
        $GLOBALS['wpdb']->replace(self::blobs(), ['did' => $did, 'file_hash' => $fileHash, 'blob_ref' => wp_json_encode($blob), 'uploaded_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function forgetBlobs(string $did): void
    {
        $GLOBALS['wpdb']->delete(self::blobs(), ['did' => $did]);
    }

    /** @return array<string, int> published records per collection */
    public function counts(string $did): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT collection, COUNT(*) AS n FROM ' . self::records() . ' WHERE did = %s GROUP BY collection', $did), ARRAY_A) as $row) {
            $out[$row['collection']] = (int) $row['n'];
        }
        return $out;
    }
}
