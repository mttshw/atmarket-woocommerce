<?php
declare(strict_types=1);

namespace AtprotoWC;

/** What the sync reads from the shop. WooCommerce in production, arrays in tests. */
interface Catalogue
{
    /** @return array{name: string, description: ?string, currency: string, url: string, zones: list<array{name: string, countries: list<string>}>, logo_image: ?int} */
    public function store(): array;

    /**
     * Products to publish, already filtered to what should be public.
     * Each: id, name, permalink, description, short_description, brand, tags,
     * categories, is_digital, created, modified, image_ids, and variants
     * (key, sku, title, price, in_stock, purchasable, options, is_digital).
     * @return iterable<array<string, mixed>>
     */
    public function products(): iterable;

    /**
     * An image ready to upload: at most 1 MB.
     * @return array{bytes: string, mime: string, hash: string}|null hash identifies the source file
     */
    public function image(int $attachmentId): ?array;
}
