<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Records;
use PHPUnit\Framework\TestCase;

final class RecordsTest extends TestCase
{
    public function testMinorUnitsFollowTheCurrency(): void
    {
        $this->assertSame(1250, Records::minorUnits('12.50', 'GBP'));
        $this->assertSame(1999, Records::minorUnits('19.99', 'usd'));
        $this->assertSame(1500, Records::minorUnits('1500', 'JPY'));
        $this->assertSame(12345, Records::minorUnits('12.345', 'KWD'));
        $this->assertSame(1001, Records::minorUnits('10.005', 'GBP'), 'rounds half up, never floors 10.005 to 1000');
        $this->assertNull(Records::minorUnits('', 'GBP'));
    }

    public function testProductRecord(): void
    {
        $p = ArrayCatalogue::simple(7);
        $p['variants'] = [
            'id:1' => ['key' => 'id:1', 'sku' => null, 'title' => 'A3', 'price' => '25', 'in_stock' => true, 'purchasable' => true, 'options' => ['Size' => 'A3', 'Paper' => 'Matte']],
            'id:2' => ['key' => 'id:2', 'sku' => null, 'title' => 'A4', 'price' => '18', 'in_stock' => false, 'purchasable' => true, 'options' => ['Size' => 'A4', 'Paper' => 'Matte']],
        ];
        $r = Records::product($p, []);
        $this->assertSame('shop.lgtm.commerce.product', $r['$type']);
        $this->assertSame('active', $r['status']);
        $this->assertSame("A two-colour risograph print.\nA3 & A4.", $r['description']);
        $this->assertSame([['name' => 'Size', 'values' => ['A3', 'A4']], ['name' => 'Paper', 'values' => ['Matte']]], $r['options']);
        $this->assertSame('2026-09-01T10:00:00.000Z', $r['createdAt']);
        $this->assertSame('https://shop.example.com/product/harbour-7/', $r['url']);
        $this->assertSame(['print', 'riso'], $r['tags']);
        $this->assertSame('Prints', $r['category']);
        $this->assertArrayNotHasKey('images', $r);
    }

    public function testVariantRecordNeverCarriesStockCounts(): void
    {
        $p = ArrayCatalogue::simple(7);
        $v = ['key' => 'sku:X', 'sku' => 'X', 'title' => null, 'price' => '25.00', 'in_stock' => false, 'purchasable' => true, 'options' => ['Size' => 'A3']];
        $r = Records::variant($v, $p, 'at://did:plc:x/shop.lgtm.commerce.product/3l2', 'gbp');
        $this->assertSame(['$type', 'product', 'sku', 'kind', 'availability', 'pricing', 'unitAmount', 'currency', 'optionValues'], array_keys($r));
        $this->assertSame('sold_out', $r['availability']);
        $this->assertSame(2500, $r['unitAmount']);
        $this->assertSame('GBP', $r['currency']);
        $this->assertSame('physical', $r['kind']);
        $this->assertSame('unavailable', Records::variant(['purchasable' => false] + $v, $p, 'at://x', 'GBP')['availability']);
        $this->assertSame('digital', Records::variant(['is_digital' => true] + $v, $p, 'at://x', 'GBP')['kind']);
        $this->assertNull(Records::variant(['price' => ''] + $v, $p, 'at://x', 'GBP'));
    }

    public function testStorefrontDropsZonesWithoutCountryCodes(): void
    {
        $r = Records::storefront(['did' => 'did:plc:x', 'name' => 'Shop', 'description' => null, 'currency' => 'eur', 'url' => 'https://shop.example.com', 'zones' => [
            ['name' => 'Europe', 'countries' => ['fr', 'DE', 'de']],
            ['name' => 'Rest of world', 'countries' => []],
        ]], null);
        $this->assertSame([['code' => 'EUROPE', 'label' => 'Europe', 'countries' => ['FR', 'DE']]], $r['shippingZones']);
        $this->assertSame(['EUR'], $r['currencies']);
        $this->assertArrayNotHasKey('description', $r);
        $this->assertArrayNotHasKey('branding', $r);
    }

    public function testHashIgnoresKeyOrder(): void
    {
        $this->assertSame(Records::hash(['a' => 1, 'b' => ['c' => 2, 'd' => [1, 2]]]), Records::hash(['b' => ['d' => [1, 2], 'c' => 2], 'a' => 1]));
        $this->assertNotSame(Records::hash(['a' => [1, 2]]), Records::hash(['a' => [2, 1]]), 'list order matters');
    }
}
