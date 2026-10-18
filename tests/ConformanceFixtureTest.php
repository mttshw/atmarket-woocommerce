<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Repo\PreviewRepo;
use AtprotoWC\Sync;
use PHPUnit\Framework\TestCase;

/**
 * Publishes a representative catalogue (simple, variable with a sold-out
 * option, external, digital, with images) to an in-memory repo. With
 * ATPWC_CONFORMANCE_OUT set, writes the records there in the `wp atproto
 * preview` format, for `commerce-check --file` to verify in CI.
 */
final class ConformanceFixtureTest extends TestCase
{
    public function testRepresentativeCatalogue(): void
    {
        $catalogue = new ArrayCatalogue();
        $catalogue->files = [10 => 'jpeg-bytes'];
        $catalogue->products = [
            1 => ArrayCatalogue::simple(1, ['image_ids' => [10]]),
            2 => ArrayCatalogue::simple(2, ['name' => 'Pier print', 'image_ids' => [10], 'variants' => [
                'id:21' => ['key' => 'id:21', 'sku' => null, 'title' => null, 'price' => '30.00', 'in_stock' => true, 'purchasable' => true, 'options' => ['Size' => 'A3']],
                'id:22' => ['key' => 'id:22', 'sku' => null, 'title' => null, 'price' => '18.50', 'in_stock' => false, 'purchasable' => true, 'options' => ['Size' => 'A4']],
            ]]),
            3 => ArrayCatalogue::simple(3, ['name' => 'Print workshop', 'permalink' => 'https://tickets.example.com/workshop', 'image_ids' => [10]]),
            4 => ArrayCatalogue::simple(4, ['name' => 'Zine (PDF)', 'is_digital' => true, 'image_ids' => [10]]),
        ];
        $repo = new PreviewRepo('did:plc:fixtureaaaaaaaaaaaaaaaaa');
        $res = (new Sync($catalogue, new MemorySyncState()))->run($repo);

        $this->assertSame([], $res['skipped']);
        $this->assertCount(1 + 4 + 5, $repo->records);

        $out = getenv('ATPWC_CONFORMANCE_OUT');
        if ($out) {
            @mkdir(dirname($out), 0777, true);
            file_put_contents($out, json_encode(['did' => $repo->did(), 'records' => $repo->records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }
}
