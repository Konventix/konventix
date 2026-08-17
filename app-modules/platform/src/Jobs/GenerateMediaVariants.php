<?php

declare(strict_types=1);

namespace Konventix\Platform\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Platform\Media\ThumbnailGenerator;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Storage\DiskResolver;

/**
 * Generates a media item's variants outside the upload request (ADR-0043).
 *
 * Dispatched only for remote disks: reading the original back and writing the
 * thumbnail are two network round trips, which would otherwise sit inside the
 * operator's upload request. On a local disk the same work is a couple of
 * file reads and stays inline, so the upload keeps returning a complete item.
 *
 * Idempotent on `variants_generated_at`: a replay after the stamp is set does
 * nothing, so a retry never rewrites a variant another reader may already be
 * caching. Items whose source is not a raster image (SVG, PDF) get an empty
 * map and the same stamp — "processed, nothing to make" is a state, not a
 * pending one.
 *
 * Looked up by ULID with `withoutBrandScope`, like everything else in the
 * library: the ULID implies the brand, and the job runs wherever it runs.
 */
final class GenerateMediaVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Transient storage failures are the realistic cause; three is plenty. */
    public int $tries = 3;

    public function __construct(public readonly string $mediaItemId) {}

    public function handle(ThumbnailGenerator $thumbnails, DiskResolver $disks): void
    {
        $item = MediaItem::withoutBrandScope()->find($this->mediaItemId);

        if ($item === null || $item->variants_generated_at !== null) {
            return;
        }

        $variants = $thumbnails->generate($disks->filesystem($item->disk), $item->path, $item->mime_type);

        $item->forceFill([
            'variants' => $variants,
            'variants_generated_at' => now(),
        ])->save();
    }
}
