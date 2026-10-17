<?php
declare(strict_types=1);

namespace AtprotoWC;

/**
 * Pure mapping from catalogue data to shop.lgtm.commerce.* records. No
 * WordPress calls, so it can be tested on its own.
 *
 * Records also carry fields the lexicons don't declare yet (url, tags,
 * category, website, updatedAt, variant title): atproto records may carry
 * extra fields, and directories such as Atmarket read them.
 */
final class Records
{
    public const NS = 'shop.lgtm.commerce';
    public const STOREFRONT = self::NS . '.storefront';
    public const PRODUCT = self::NS . '.product';
    public const VARIANT = self::NS . '.variant';

    /** ISO 4217 minor-unit exponents that aren't 2. */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** "12.50" GBP → 1250. WooCommerce stores prices as decimal strings in the store currency. */
    public static function minorUnits(string $price, string $currency): ?int
    {
        if (!is_numeric($price)) {
            return null;
        }
        $exp = self::EXPONENTS[strtoupper($currency)] ?? 2;
        return max(0, (int) round((float) $price * (10 ** $exp)));
    }

    public static function plainText(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $text));
        return $text === '' ? null : $text;
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    /** "2026-09-01 10:00:00" or any parseable date → RFC 3339 UTC. */
    public static function datetime(?string $date): string
    {
        $ts = $date ? strtotime($date) : false;
        return gmdate('Y-m-d\TH:i:s.000\Z', $ts === false ? 0 : $ts);
    }

    /**
     * @param array{did: string, name: string, description: ?string, currency: string, url: string, zones: list<array{name: string, countries: list<string>}>} $store
     * @param array<string, mixed>|null $logo blob ref
     * @return array<string, mixed>
     */
    public static function storefront(array $store, ?array $logo): array
    {
        $zones = [];
        foreach ($store['zones'] as $z) {
            $countries = array_values(array_unique(array_filter(
                array_map('strtoupper', $z['countries']),
                static fn ($c) => (bool) preg_match('/^[A-Z]{2}$/', $c),
            )));
            if ($countries) {
                $code = trim((string) preg_replace('/[^A-Z0-9]+/', '-', strtoupper($z['name'])), '-');
                $zones[] = ['code' => self::clip($code ?: 'ZONE', 32), 'label' => self::clip($z['name'], 128), 'countries' => $countries];
            }
        }
        return array_filter([
            '$type' => self::STOREFRONT,
            'storeDid' => $store['did'],
            'displayName' => self::clip($store['name'] !== '' ? $store['name'] : 'Shop', 256),
            'description' => $store['description'] ? self::clip($store['description'], 2000) : null,
            'currencies' => [strtoupper($store['currency'])],
            'shippingZones' => $zones ?: null,
            'branding' => $logo ? ['logo' => $logo] : null,
            'website' => $store['url'],
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $p a product from Catalogue::products()
     * @param list<array<string, mixed>> $images blob refs
     * @return array<string, mixed>
     */
    public static function product(array $p, array $images): array
    {
        // Option axes, in order, from the variants' option values.
        $axes = [];
        foreach ($p['variants'] as $v) {
            foreach ($v['options'] as $name => $value) {
                if ($value === '') {
                    continue;
                }
                $axes[$name] ??= [];
                if (!in_array($value, $axes[$name], true)) {
                    $axes[$name][] = self::clip($value, 128);
                }
            }
        }
        $description = self::plainText($p['description'] ?: $p['short_description']);
        $tags = array_slice(array_values(array_filter($p['tags'])), 0, 50);
        return array_filter([
            '$type' => self::PRODUCT,
            'name' => self::clip($p['name'] !== '' ? $p['name'] : 'Untitled', 512),
            'description' => $description ? self::clip($description, 20000) : null,
            'status' => 'active',
            'images' => $images ?: null,
            'brand' => $p['brand'] ? self::clip($p['brand'], 256) : null,
            'options' => $axes ? array_map(static fn ($name, $values) => ['name' => self::clip((string) $name, 128), 'values' => $values], array_keys($axes), $axes) : null,
            'createdAt' => self::datetime($p['created']),
            'updatedAt' => self::datetime($p['modified']),
            'url' => $p['permalink'],
            'tags' => $tags ?: null,
            'category' => $p['categories'][0] ?? null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $v a variant of the product
     * @param array<string, mixed> $p the product
     * @return array<string, mixed>|null null when it has no usable price
     */
    public static function variant(array $v, array $p, string $productUri, string $currency): ?array
    {
        $amount = self::minorUnits((string) $v['price'], $currency);
        if ($amount === null) {
            return null;
        }
        $options = [];
        foreach ($v['options'] as $name => $value) {
            if ($value !== '') {
                $options[] = ['name' => self::clip((string) $name, 128), 'value' => self::clip($value, 128)];
            }
        }
        return array_filter([
            '$type' => self::VARIANT,
            'product' => $productUri,
            'sku' => $v['sku'] ? self::clip($v['sku'], 128) : null,
            'kind' => ($v['is_digital'] ?? $p['is_digital']) ? 'digital' : 'physical',
            // Stock status only, never counts.
            'availability' => !$v['purchasable'] ? 'unavailable' : ($v['in_stock'] ? 'available' : 'sold_out'),
            'pricing' => 'fixed',
            'unitAmount' => $amount,
            'currency' => strtoupper($currency),
            'optionValues' => $options ?: null,
            'title' => $v['title'] ?: null,
        ], static fn ($x) => $x !== null);
    }

    /** Stable hash of a record, independent of key order, so unchanged records are never rewritten. */
    public static function hash(array $record): string
    {
        $sort = static function ($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            if (array_is_list($v)) {
                return array_map($sort, $v);
            }
            ksort($v);
            return array_map($sort, $v);
        };
        return hash('sha256', json_encode($sort($record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
