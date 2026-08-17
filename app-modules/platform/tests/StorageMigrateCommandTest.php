<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Models\MediaItem;

/**
 * `storage:migrate` (ADR-0043): migration is a data operation, not a cutover.
 * Every copy is verified before the owning record is repointed, so an
 * interrupted or partly failed run leaves a readable half-migrated state
 * rather than dangling references.
 */
function migratableMediaItem(string $disk = 'media'): MediaItem
{
    $item = MediaItem::factory()->create([
        'disk' => $disk,
        'path' => 'brand/original.png',
        'variants' => ['thumb' => 'brand/original.thumb.webp'],
    ]);

    Storage::disk($disk)->put($item->path, 'original-bytes');
    Storage::disk($disk)->put($item->variants['thumb'], 'thumb-bytes');

    return $item;
}

it('copies every file, verifies it, and repoints the record', function (): void {
    $this->withBrand();
    Storage::fake('media');
    Storage::fake('media_s3');

    $item = migratableMediaItem();

    $this->artisan('storage:migrate', [
        '--purpose' => 'media',
        '--from' => 'media',
        '--to' => 'media_s3',
        '--no-interaction' => true,
    ])->assertSuccessful();

    Storage::disk('media_s3')->assertExists($item->path);
    Storage::disk('media_s3')->assertExists($item->variants['thumb']);

    expect($item->refresh()->disk)->toBe('media_s3');

    // Without --delete the source stays: a migration that has not been
    // verified end to end is not a reason to have thrown anything away.
    Storage::disk('media')->assertExists($item->path);
});

it('deletes the source only when asked, and only after the copy', function (): void {
    $this->withBrand();
    Storage::fake('media');
    Storage::fake('media_s3');

    $item = migratableMediaItem();

    $this->artisan('storage:migrate', [
        '--purpose' => 'media',
        '--from' => 'media',
        '--to' => 'media_s3',
        '--delete' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Storage::disk('media_s3')->assertExists($item->path);
    Storage::disk('media')->assertMissing($item->path);
    Storage::disk('media')->assertMissing($item->variants['thumb']);
});

it('leaves a record on its old disk when one of its files is missing', function (): void {
    $this->withBrand();
    Storage::fake('media');
    Storage::fake('media_s3');

    $intact = migratableMediaItem();

    $broken = MediaItem::factory()->create([
        'disk' => 'media',
        'path' => 'brand/never-written.png',
        'variants' => [],
    ]);

    $this->artisan('storage:migrate', [
        '--purpose' => 'media',
        '--from' => 'media',
        '--to' => 'media_s3',
        '--no-interaction' => true,
    ])->assertFailed();

    // The healthy item moved; the broken one still points where its (absent)
    // file was, so a re-run picks it up again instead of silently orphaning it.
    expect($intact->refresh()->disk)->toBe('media_s3')
        ->and($broken->refresh()->disk)->toBe('media');
});

it('touches nothing on a dry run', function (): void {
    $this->withBrand();
    Storage::fake('media');
    Storage::fake('media_s3');

    $item = migratableMediaItem();

    $this->artisan('storage:migrate', [
        '--purpose' => 'media',
        '--from' => 'media',
        '--to' => 'media_s3',
        '--dry-run' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Storage::disk('media_s3')->assertMissing($item->path);
    expect($item->refresh()->disk)->toBe('media');
});

it('refuses an unknown disk and a no-op move', function (): void {
    $this->withBrand();

    $this->artisan('storage:migrate', [
        '--purpose' => 'media',
        '--from' => 'media',
        '--to' => 'nowhere',
        '--no-interaction' => true,
    ])->assertFailed();

    $this->artisan('storage:migrate', [
        '--purpose' => 'media',
        '--from' => 'media',
        '--to' => 'media',
        '--no-interaction' => true,
    ])->assertFailed();
});
