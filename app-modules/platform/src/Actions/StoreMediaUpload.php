<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Jobs\GenerateMediaVariants;
use Konventix\Platform\Media\ThumbnailGenerator;
use Konventix\Platform\Models\MediaItem;

/**
 * Accepts an upload into the brand's media library (ADR-0035): stores the
 * file brand-sharded on the configured media disk, generates variants, and
 * creates the item row. Takes an explicit brand ULID — uploads happen on the
 * operator admin host, which resolves no ambient brand (ADR-0029).
 *
 * Variants are generated inline on a local disk and queued on a remote one
 * (ADR-0043): reading the original back and writing the thumbnail are two
 * network round trips, and an operator's upload request should not wait for
 * them. The item exists either way — only its `thumb` arrives late.
 */
final class StoreMediaUpload
{
    public function __construct(
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    public function handle(string $brandId, UploadedFile $file, ?string $altText, ?string $uploadedByUserId): MediaItem
    {
        // Where NEW media lands (ADR-0043). The disk is recorded on the row,
        // so pointing this at object storage leaves everything already
        // uploaded readable on the disk it was written to.
        $disk = (string) config('filesystems.media_disk', 'media');

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = sprintf('%s/%s.%s', $brandId, (string) Str::ulid(), $extension);

        $file->storeAs(dirname($path), basename($path), $disk);

        $inline = config("filesystems.disks.{$disk}.driver") === 'local';

        $variants = $inline
            ? $this->thumbnails->generate($disk, $path, $file->getMimeType() ?? 'application/octet-stream')
            : [];

        $item = DB::transaction(function () use ($brandId, $disk, $inline, $file, $path, $variants, $altText, $uploadedByUserId): MediaItem {
            $item = new MediaItem([
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'alt_text' => $altText,
                'variants' => $variants,
                'uploaded_by_user_id' => $uploadedByUserId,
            ]);
            // Explicit brand from the request, not ambient context (ADR-0029).
            $item->brand_id = $brandId;
            // Null means "still to be generated" — an empty map alone cannot
            // say whether a file has no variants or has not been processed.
            $item->variants_generated_at = $inline ? now() : null;
            $item->save();

            return $item;
        });

        if (! $inline) {
            // After the transaction: the job looks the row up by ULID and
            // would find nothing inside it.
            GenerateMediaVariants::dispatch($item->getKey());
        }

        return $item;
    }
}
