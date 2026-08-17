<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Jobs\PurgeBrandMedia;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Storage\DiskResolver;

/**
 * ADR-0043: media rows cascade with the brand, files do not. On object
 * storage that is a bill nobody notices, so a deleted brand's media prefix is
 * purged — and only media: statutory retention outlives the brand, so the
 * invoice archive is never touched (ADR-0031).
 */
it('queues a purge of every disk the brand kept media on', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    MediaItem::factory()->create(['disk' => 'media']);
    MediaItem::factory()->create(['disk' => 'media_s3']);

    $brand->delete();

    // Collected while the rows still existed — afterwards nothing knows where
    // this brand's files lived.
    Queue::assertPushed(PurgeBrandMedia::class, function (PurgeBrandMedia $job) use ($brand): bool {
        $disks = $job->disks;
        sort($disks);

        return $job->brandId === $brand->getKey() && $disks === ['media', 'media_s3'];
    });
});

it('queues nothing for a brand that never had media', function (): void {
    $brand = $this->withBrand();
    Queue::fake();

    $brand->delete();

    Queue::assertNotPushed(PurgeBrandMedia::class);
});

it('deletes the brand prefix and leaves other brands alone', function (): void {
    Storage::fake('media');

    Storage::disk('media')->put('brand-a/logo.png', 'a');
    Storage::disk('media')->put('brand-a/hero.jpg', 'a');
    Storage::disk('media')->put('brand-b/logo.png', 'b');

    (new PurgeBrandMedia('brand-a', ['media']))->handle(app(DiskResolver::class));

    Storage::disk('media')->assertMissing('brand-a/logo.png');
    Storage::disk('media')->assertMissing('brand-a/hero.jpg');
    Storage::disk('media')->assertExists('brand-b/logo.png');
});

it('skips a disk that is no longer configured instead of failing', function (): void {
    Storage::fake('media');
    Storage::disk('media')->put('brand-a/logo.png', 'a');

    (new PurgeBrandMedia('brand-a', ['retired_bucket', 'media']))->handle(app(DiskResolver::class));

    Storage::disk('media')->assertMissing('brand-a/logo.png');
});
