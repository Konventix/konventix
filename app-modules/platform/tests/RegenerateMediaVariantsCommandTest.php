<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Actions\StoreMediaUpload;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;

/**
 * `media:regenerate-variants` is the sweep that fills in a variant invented
 * after an upload happened — `ticket_banner` (2026-09-21) being the first,
 * which is why it is the post-deploy step for 0.7.0 in
 * `docs/operations.md`.
 *
 * Two things every case here leans on: the walk is
 * `MediaItem::withoutBrandScope()` because a console run resolves no brand
 * (ADR-0029), and it is idempotent — a second pass must write nothing at
 * all, not merely produce the same bytes.
 */

/**
 * An item as it looked before `ticket_banner` existed: a real picture on
 * disk, a `thumb` beside it, and no band.
 */
function mediaWithoutTicketBanner(string $brandId, int $width = 2480, int $height = 1000): MediaItem
{
    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brandId,
        file: UploadedFile::fake()->image('banner.png', $width, $height),
        altText: null,
        uploadedByUserId: null,
    );

    Storage::disk('media')->delete($item->variants['ticket_banner']);

    $item->forceFill([
        'variants' => ['thumb' => $item->variants['thumb']],
        'variants_generated_at' => now()->subMonth(),
    ])->save();

    return $item->refresh();
}

it('backfills a missing ticket_banner and writes nothing on a second run', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media');

    $item = mediaWithoutTicketBanner($brand->getKey());

    expect($item->variants)->not->toHaveKey('ticket_banner');

    $this->artisan('media:regenerate-variants')->assertSuccessful();

    $item->refresh();

    expect($item->variants)->toHaveKeys(['thumb', 'ticket_banner']);
    Storage::disk('media')->assertExists($item->variants['ticket_banner']);

    // The band's own shape, cut by the same generator the upload uses.
    [$width, $height] = getimagesizefromstring((string) Storage::disk('media')->get($item->variants['ticket_banner']));
    expect($width / $height)->toBeGreaterThan(9.0);

    $stampedAt = $item->variants_generated_at;
    $thumbBytes = (string) Storage::disk('media')->get($item->variants['thumb']);

    $this->artisan('media:regenerate-variants')->assertSuccessful();

    // Idempotent: the row is untouched and the thumb was never re-encoded.
    expect($item->refresh()->variants_generated_at->equalTo($stampedAt))->toBeTrue()
        ->and((string) Storage::disk('media')->get($item->variants['thumb']))->toBe($thumbBytes);
});

it('cuts only the variants named by --only', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media');

    $item = mediaWithoutTicketBanner($brand->getKey());

    $this->artisan('media:regenerate-variants', ['--only' => ['thumb']])->assertSuccessful();

    // `thumb` is already there, so there was nothing to do — and the band
    // was not asked for and must not appear.
    expect($item->refresh()->variants)->not->toHaveKey('ticket_banner');

    $this->artisan('media:regenerate-variants', ['--only' => ['ticket_banner']])->assertSuccessful();

    expect($item->refresh()->variants)->toHaveKey('ticket_banner');
});

it('refuses a variant it does not know how to cut', function (): void {
    $this->withBrand();

    $this->artisan('media:regenerate-variants', ['--only' => ['poster']])->assertFailed();
});

it('narrows the walk to one brand', function (): void {
    Storage::fake('media');

    $first = $this->withBrand();
    $mine = mediaWithoutTicketBanner($first->getKey());

    // A second, distinct brand — `withBrand()` memoises the first one.
    $other = $this->withBrand(Brand::factory()->create());
    $theirs = mediaWithoutTicketBanner($other->getKey());

    $this->artisan('media:regenerate-variants', ['--brand' => $first->slug])->assertSuccessful();

    expect($mine->refresh()->variants)->toHaveKey('ticket_banner')
        ->and($theirs->refresh()->variants)->not->toHaveKey('ticket_banner');
});

it('fails when --brand names no brand', function (): void {
    $this->withBrand();

    $this->artisan('media:regenerate-variants', ['--brand' => 'no-such-brand'])->assertFailed();
});

/**
 * `ThumbnailGenerator` never upscales, so a picture too small for the band
 * gets no `ticket_banner` — and must not be re-decoded on every sweep just
 * because the key stays absent.
 */
it('leaves a picture too small for the band alone, run after run', function (): void {
    $brand = $this->withBrand();
    Storage::fake('media');

    $item = app(StoreMediaUpload::class)->handle(
        brandId: $brand->getKey(),
        file: UploadedFile::fake()->image('tiny.png', 4, 4),
        altText: null,
        uploadedByUserId: null,
    );

    $stampedAt = $item->variants_generated_at;

    $this->artisan('media:regenerate-variants')->assertSuccessful();

    expect($item->refresh()->variants)->toBe([])
        ->and($item->variants_generated_at->equalTo($stampedAt))->toBeTrue();
});
