<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Data\MediaItemData;
use Konventix\Platform\Jobs\GenerateMediaVariants;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Media\ThumbnailGenerator;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Storage\DiskResolver;

/**
 * ADR-0043: how a media ULID becomes a URL depends on the disk it lives on.
 * A `public_direct` disk hands out its own URL — the local disk through the
 * `public/storage` symlink, an object store through a public bucket — while
 * anything else is served through the application's media route.
 */
it('hands out the disk\'s own url for a public_direct disk', function (): void {
    $this->withBrand();

    $item = MediaItem::factory()->create(['disk' => 'media']);

    expect(app(MediaLibrary::class)->urlFor($item->getKey()))
        ->toBe(Storage::disk('media')->url($item->path));
});

it('routes through the application for a disk that is not public_direct', function (): void {
    $this->withBrand();

    // An S3 bucket the operator kept private: readable by the app, not by
    // the browser.
    Config::set('filesystems.disks.media.public_direct', false);

    $item = MediaItem::factory()->create([
        'disk' => 'media',
        'variants' => ['thumb' => 'brand/item.thumb.webp'],
    ]);

    expect(app(MediaLibrary::class)->urlFor($item->getKey()))
        ->toBe(route('platform.media.show', ['media' => $item->getKey()]))
        ->and(app(MediaLibrary::class)->urlFor($item->getKey(), 'thumb'))
        ->toBe(route('platform.media.show', ['media' => $item->getKey(), 'variant' => 'thumb']));
});

it('streams a media item and its variant through the media route', function (): void {
    $this->withBrand();
    Storage::fake('media');

    Storage::disk('media')->put('brand/item.png', 'original-bytes');
    Storage::disk('media')->put('brand/item.thumb.webp', 'thumb-bytes');

    $item = MediaItem::factory()->create([
        'disk' => 'media',
        'path' => 'brand/item.png',
        'mime_type' => 'image/png',
        'variants' => ['thumb' => 'brand/item.thumb.webp'],
    ]);

    $response = $this->get(route('platform.media.show', ['media' => $item->getKey()]));
    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        // Keys are never overwritten, so a year is safe (ADR-0043). Symfony
        // normalises the directive order, hence the alphabetical spelling.
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    expect($response->streamedContent())->toBe('original-bytes');

    expect($this->get(route('platform.media.show', ['media' => $item->getKey(), 'variant' => 'thumb']))
        ->assertOk()
        ->streamedContent())->toBe('thumb-bytes');
});

it('404s an unknown item, an unknown variant, and a missing object', function (): void {
    $this->withBrand();
    Storage::fake('media');

    $item = MediaItem::factory()->create(['disk' => 'media', 'path' => 'brand/gone.png']);

    // Row exists, object does not — a half-migrated library must not serve
    // an empty 200.
    $this->get(route('platform.media.show', ['media' => $item->getKey()]))->assertNotFound();
    $this->get(route('platform.media.show', ['media' => $item->getKey(), 'variant' => 'thumb']))->assertNotFound();
    $this->get(route('platform.media.show', ['media' => (string) Str::ulid()]))->assertNotFound();
});

it('serves media on the operator admin host, where brand resolution does not run', function (): void {
    $this->withBrand();
    Storage::fake('media');
    Storage::disk('media')->put('brand/item.png', 'original-bytes');

    $item = MediaItem::factory()->create(['disk' => 'media', 'path' => 'brand/item.png']);

    // The route escapes the `web` group on purpose: the console renders the
    // same URLs on the admin host, where ResolveBrand would 404 them.
    $this->get('http://admin.localhost/media/'.$item->getKey())->assertOk();
});

it('writes new uploads to the configured media disk', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media');
    Storage::fake('media_s3');

    Config::set('filesystems.media_disk', 'media_s3');

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->image('logo.png', 600, 400),
        altText: null,
        uploadedByUserId: null,
    );

    // The disk is recorded on the row, so items uploaded before the switch
    // keep resolving from the disk they were written to.
    expect($item->disk)->toBe('media_s3');
    Storage::disk('media_s3')->assertExists($item->path);
    Storage::disk('media')->assertMissing($item->path);
});

/**
 * ADR-0043: variant generation is inline on a local disk and queued on a
 * remote one — two network round trips have no business inside an operator's
 * upload request.
 */
it('generates variants inline on a local disk', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media');
    Queue::fake();

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->image('logo.png', 1200, 800),
        altText: null,
        uploadedByUserId: null,
    );

    expect($item->variants)->toHaveKey('thumb')
        ->and($item->variants_generated_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

it('queues variant generation for a remote disk and marks the item pending', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media_s3');
    Queue::fake();

    Config::set('filesystems.media_disk', 'media_s3');

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->image('logo.png', 1200, 800),
        altText: null,
        uploadedByUserId: null,
    );

    // The item is usable immediately; only its thumbnail arrives late.
    expect($item->variants)->toBe([])
        ->and($item->variants_generated_at)->toBeNull()
        ->and(MediaItemData::fromModel($item->refresh(), app(MediaLibrary::class))->variantsPending)->toBeTrue();

    Queue::assertPushed(GenerateMediaVariants::class, fn (GenerateMediaVariants $job): bool => $job->mediaItemId === $item->getKey());
});

it('fills the variants when the job runs, and does nothing on replay', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media_s3');

    Config::set('filesystems.media_disk', 'media_s3');

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->image('logo.png', 1200, 800),
        altText: null,
        uploadedByUserId: null,
    );

    (new GenerateMediaVariants($item->getKey()))->handle(app(ThumbnailGenerator::class), app(DiskResolver::class));

    $item->refresh();

    expect($item->variants)->toHaveKey('thumb')
        ->and($item->variants_generated_at)->not->toBeNull();
    Storage::disk('media_s3')->assertExists($item->variants['thumb']);

    // A retry after the stamp must not rewrite a variant readers may already
    // be caching (keys are immutable, ADR-0043).
    $stampedAt = $item->variants_generated_at;
    (new GenerateMediaVariants($item->getKey()))->handle(app(ThumbnailGenerator::class), app(DiskResolver::class));

    expect($item->refresh()->variants_generated_at->equalTo($stampedAt))->toBeTrue();
});

it('marks a non-raster upload processed rather than perpetually pending', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media_s3');

    Config::set('filesystems.media_disk', 'media_s3');

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->create('poster.pdf', 12, 'application/pdf'),
        altText: null,
        uploadedByUserId: null,
    );

    (new GenerateMediaVariants($item->getKey()))->handle(app(ThumbnailGenerator::class), app(DiskResolver::class));

    // "Processed, nothing to make" is a state — not a spinner forever.
    expect($item->refresh()->variants)->toBe([])
        ->and($item->variants_generated_at)->not->toBeNull();
});
