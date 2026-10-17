<?php
declare(strict_types=1);

namespace AtprotoWC\Wp;

use AtprotoWC\Catalogue;

/**
 * Reads the shop for the sync. Only what a customer could see goes out:
 * published, not hidden from the catalogue, not password-protected, and not
 * in a category the owner excluded. Stock counts, costs and customer data
 * are never read here.
 */
final class WcCatalogue implements Catalogue
{
    private const MAX_BLOB_BYTES = 1_000_000;
    private const UPLOADABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** @param list<int> $excludedCategories product_cat term ids */
    public function __construct(private array $excludedCategories = [])
    {
    }

    public function store(): array
    {
        $logo = (int) get_theme_mod('custom_logo') ?: (int) get_option('site_icon');
        return [
            'name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'description' => wp_specialchars_decode(get_bloginfo('description'), ENT_QUOTES) ?: null,
            'currency' => get_woocommerce_currency(),
            'url' => home_url(),
            'zones' => $this->zones(),
            'logo_image' => $logo ?: null,
        ];
    }

    /** @return list<array{name: string, countries: list<string>}> */
    private function zones(): array
    {
        $continents = WC()->countries->get_continents();
        $zones = [];
        foreach (\WC_Shipping_Zones::get_zones() as $zone) {
            $countries = [];
            foreach ($zone['zone_locations'] ?? [] as $loc) {
                $code = (string) $loc->code;
                $countries = match ($loc->type) {
                    'country' => [...$countries, $code],
                    'state' => [...$countries, explode(':', $code)[0]],
                    'continent' => [...$countries, ...($continents[$code]['countries'] ?? [])],
                    default => $countries, // postcodes narrow a zone, they don't name countries
                };
            }
            $zones[] = ['name' => (string) $zone['zone_name'], 'countries' => array_values(array_unique($countries))];
        }
        return $zones;
    }

    public function products(): iterable
    {
        $page = 1;
        do {
            $ids = wc_get_products([
                'status' => 'publish',
                'type' => ['simple', 'variable', 'external'],
                'limit' => 100,
                'page' => $page++,
                'return' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            foreach ($ids as $id) {
                $product = wc_get_product($id);
                if ($product && $this->isPublic($product)) {
                    yield $this->product($product);
                }
            }
        } while (count($ids) === 100);
    }

    private ?string $defaultCategory = null;

    private function defaultCategory(): string
    {
        if ($this->defaultCategory === null) {
            $term = get_term((int) get_option('default_product_cat'), 'product_cat');
            $this->defaultCategory = $term instanceof \WP_Term ? html_entity_decode($term->name) : '';
        }
        return $this->defaultCategory;
    }

    private function isPublic(\WC_Product $p): bool
    {
        if ($p->get_catalog_visibility() === 'hidden' || post_password_required($p->get_id())) {
            return false;
        }
        return !array_intersect($p->get_category_ids(), $this->excludedCategories);
    }

    /** @return array<string, mixed> */
    private function product(\WC_Product $p): array
    {
        $id = $p->get_id();
        $names = static fn (string $taxonomy) => taxonomy_exists($taxonomy)
            ? array_map('html_entity_decode', wp_get_post_terms($id, $taxonomy, ['fields' => 'names']) ?: [])
            : [];
        $isDigital = $p->is_virtual() || $p->is_downloadable();
        return [
            'id' => $id,
            'name' => html_entity_decode($p->get_name(), ENT_QUOTES),
            // External products are bought on another site; send buyers there.
            'permalink' => $p instanceof \WC_Product_External && $p->get_product_url() ? $p->get_product_url() : get_permalink($id),
            'description' => $p->get_description(),
            'short_description' => $p->get_short_description(),
            'brand' => $names('product_brand')[0] ?? null,
            'tags' => $names('product_tag'),
            // WooCommerce's fallback "Uncategorized" says nothing about the product.
            'categories' => array_values(array_diff($names('product_cat'), [$this->defaultCategory()])),
            'is_digital' => $isDigital,
            'created' => $p->get_date_created()?->date('c'),
            'modified' => $p->get_date_modified()?->date('c'),
            'image_ids' => array_values(array_filter([$p->get_image_id(), ...$p->get_gallery_image_ids()])),
            'variants' => $p instanceof \WC_Product_Variable ? $this->variations($p) : [$this->single($p)],
        ];
    }

    /** @return array<string, mixed> */
    private function single(\WC_Product $p): array
    {
        $sku = $p->get_sku();
        return [
            'key' => $sku !== '' ? 'sku:' . $sku : 'default',
            'sku' => $sku ?: null,
            'title' => null,
            'price' => (string) $p->get_price(),
            'in_stock' => $p->is_in_stock(),
            // External products can't be bought here but are for sale.
            'purchasable' => $p instanceof \WC_Product_External || $p->is_purchasable(),
            'options' => [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function variations(\WC_Product_Variable $parent): array
    {
        $out = [];
        foreach ($parent->get_children() as $childId) {
            $v = wc_get_product($childId);
            if (!$v instanceof \WC_Product_Variation || $v->get_status() !== 'publish') {
                continue;
            }
            $options = [];
            foreach ($v->get_variation_attributes(false) as $attribute => $value) {
                $label = wc_attribute_label($attribute, $parent);
                if ($value !== '' && taxonomy_exists($attribute)) {
                    $term = get_term_by('slug', $value, $attribute);
                    $value = $term ? $term->name : $value;
                }
                $options[html_entity_decode($label, ENT_QUOTES)] = html_entity_decode((string) $value, ENT_QUOTES);
            }
            $sku = $v->get_sku();
            // The parent's SKU is inherited by variations without their own, so it can't identify one.
            $ownSku = $sku !== '' && $sku !== $parent->get_sku() ? $sku : null;
            $key = $ownSku !== null ? 'sku:' . $ownSku : 'id:' . $childId;
            $out[$key] = [
                'key' => $key,
                'sku' => $ownSku,
                'title' => null,
                'price' => (string) $v->get_price(),
                'in_stock' => $v->is_in_stock(),
                'purchasable' => $v->is_purchasable(),
                'options' => $options,
                'is_digital' => $v->is_virtual() || $v->is_downloadable(),
            ];
        }
        return $out;
    }

    public function image(int $attachmentId): ?array
    {
        $path = get_attached_file($attachmentId);
        if (!$path || !is_readable($path)) {
            return null;
        }
        $mime = (string) (wp_check_filetype($path)['type'] ?? '');
        $hash = (string) hash_file('sha256', $path);
        if (in_array($mime, self::UPLOADABLE, true) && filesize($path) <= self::MAX_BLOB_BYTES) {
            return ['bytes' => (string) file_get_contents($path), 'mime' => $mime, 'hash' => $hash];
        }
        // Too big (or an odd format): shrink to 1600px JPEG, lowering quality until it fits.
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return null;
        }
        $editor->resize(1600, 1600, false);
        foreach ([82, 70, 55, 40] as $quality) {
            $editor->set_quality($quality);
            $tmp = wp_tempnam('atpwc') . '.jpg';
            $saved = $editor->save($tmp, 'image/jpeg');
            if (is_wp_error($saved)) {
                return null;
            }
            $bytes = (string) file_get_contents($saved['path']);
            @unlink($saved['path']);
            @unlink($tmp);
            if (strlen($bytes) <= self::MAX_BLOB_BYTES) {
                return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'hash' => $hash];
            }
        }
        return null;
    }
}
