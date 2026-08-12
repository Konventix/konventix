<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Platform\Media\ThumbnailGenerator;
use Konventix\Platform\Models\MediaItem;

/**
 * Accepts an upload into the brand's media library (ADR-0035): stores the
 * file brand-sharded on the `media` disk, generates variants, and creates
 * the item row. Takes an explicit brand ULID — uploads happen on the
 * operator admin host, which resolves no ambient brand (ADR-0029).
 */
final class StoreMediaUpload
{
    private const string DISK = 'media';

    public function __construct(
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    public function handle(string $brandId, UploadedFile $file, ?string $altText, ?string $uploadedByUserId): MediaItem
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = sprintf('%s/%s.%s', $brandId, (string) Str::ulid(), $extension);

        $file->storeAs(dirname($path), basename($path), self::DISK);

        $variants = $this->thumbnails->generate(self::DISK, $path, $file->getMimeType() ?? 'application/octet-stream');

        return DB::transaction(function () use ($brandId, $file, $path, $variants, $altText, $uploadedByUserId): MediaItem {
            $item = new MediaItem([
                'disk' => self::DISK,
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
            $item->save();

            return $item;
        });
    }
}
