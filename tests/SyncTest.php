<?php
declare(strict_types=1);

namespace AtprotoWC\Tests;

use AtprotoWC\Records;
use AtprotoWC\Sync;
use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase
{
    private ArrayCatalogue $catalogue;
    private MemorySyncState $state;
    private FakeRepo $repo;

    protected function setUp(): void
    {
        $this->catalogue = new ArrayCatalogue();
        $this->state = new MemorySyncState();
        $this->repo = new FakeRepo();
    }

    private function sync(bool $full = false): array
    {
        return (new Sync($this->catalogue, $this->state))->run($this->repo, $full);
    }

    private function actions(): array
    {
        return array_map(static fn ($w) => $w['action'] . ' ' . $w['collection'], $this->repo->takeWrites());
    }

    public function testFirstSyncPublishesLinkedRecords(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $res = $this->sync();
        $this->assertSame(3, $res['created']);
        $this->assertSame([], $res['skipped']);
        $this->assertArrayHasKey(Records::STOREFRONT . '/self', $this->repo->records);
        $productKey = array_values(array_filter(array_keys($this->repo->records), static fn ($k) => str_starts_with($k, Records::PRODUCT)))[0];
        $this->assertSame("at://did:plc:fakeshopaaaaaaaaaaaaaaaa/{$productKey}", $this->repo->get(Records::VARIANT)[0]['product']);
    }

    public function testNothingChangedWritesNothing(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $this->sync();
        $this->repo->takeWrites();
        $this->assertSame(3, $this->sync()['unchanged']);
        $this->sync(true);
        $this->assertSame([], $this->repo->takeWrites());
    }

    public function testOnlyTheChangedRecordIsWritten(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $this->sync();
        $this->repo->takeWrites();
        $this->catalogue->products[1]['variants']['sku:HAD-1']['price'] = '30.00';
        $this->sync();
        $this->assertSame(['update ' . Records::VARIANT], $this->actions());
        $this->assertSame(3000, $this->repo->get(Records::VARIANT)[0]['unitAmount']);
    }

    public function testRemovedProductsAreDeletedVariantsFirst(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $this->catalogue->products[2] = ArrayCatalogue::simple(2, ['name' => 'Second']);
        $this->sync();
        $this->repo->takeWrites();
        unset($this->catalogue->products[1]);
        $res = $this->sync();
        $this->assertSame(2, $res['deleted']);
        $this->assertSame(['delete ' . Records::VARIANT, 'delete ' . Records::PRODUCT], $this->actions());
        $this->assertSame(['Second'], array_column($this->repo->get(Records::PRODUCT), 'name'));
    }

    public function testRecordsDeletedElsewhereAreRecreatedWithTheSameKey(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $this->sync();
        $key = array_values(array_filter(array_keys($this->repo->records), static fn ($k) => str_starts_with($k, Records::PRODUCT)))[0];
        unset($this->repo->records[$key]);
        $this->repo->takeWrites();
        $this->sync(true);
        $this->assertSame(['create ' . Records::PRODUCT], $this->actions());
        $this->assertArrayHasKey($key, $this->repo->records);
    }

    public function testAdoptsAnExistingStorefrontAndLeavesOthersAlone(): void
    {
        $this->repo->records[Records::STOREFRONT . '/self'] = ['cid' => 'x', 'value' => ['displayName' => 'Old']];
        $this->repo->records[Records::PRODUCT . '/3zzzzzzzzzzzz'] = ['cid' => 'y', 'value' => ['name' => 'Made elsewhere']];
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $res = $this->sync();
        $this->assertSame(1, $res['untracked']);
        $this->assertSame('Southsea Prints', $this->repo->get(Records::STOREFRONT)[0]['displayName']);
        $this->assertArrayHasKey(Records::PRODUCT . '/3zzzzzzzzzzzz', $this->repo->records);
    }

    public function testRetriesOnceAfterARejectedBatch(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $this->repo->failNextWrite = true;
        $this->assertSame(3, $this->sync()['created']);
    }

    public function testImagesUploadOncePerFile(): void
    {
        $this->catalogue->files = [10 => 'jpeg-bytes', 11 => 'jpeg-bytes', 12 => 'other'];
        $this->catalogue->products[1] = ArrayCatalogue::simple(1, ['image_ids' => [10, 12, 99]]);
        $this->catalogue->products[2] = ArrayCatalogue::simple(2, ['image_ids' => [11]]);
        $this->sync();
        $this->assertSame(2, $this->repo->uploads, 'same bytes under two attachments upload once; missing files are skipped');
        $this->sync();
        $this->assertSame(2, $this->repo->uploads);
    }

    public function testProductsWithoutAPriceAreSkippedNotPublished(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1, ['variants' => []]);
        $res = $this->sync();
        $this->assertSame([['key' => 'product:1', 'error' => 'no price to publish']], $res['skipped']);
        $this->assertSame([], $this->repo->get(Records::PRODUCT));
    }

    public function testRecordsArePerAccount(): void
    {
        $this->catalogue->products[1] = ArrayCatalogue::simple(1);
        $this->sync();
        $this->repo = new FakeRepo('did:plc:secondaaaaaaaaaaaaaaaaaa');
        $this->assertSame(3, $this->sync()['created']);
    }
}
