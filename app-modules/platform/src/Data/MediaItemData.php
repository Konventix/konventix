<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One media library row for the admin index (ADR-0009, ADR-0035). URLs are
 * resolved through MediaLibrary — the page never sees a disk or path.
 */
#[TypeScript]
final class MediaItemData extends Data
{
    public function __construct(
        public string $id,
        public string $brandId,
        public string $originalFilename,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $altText,
        public ?string $url,
        public ?string $thumbUrl,
        public int $usageCount,
        /** Variants are still being generated on a remote disk (ADR-0043). */
        public bool $variantsPending,
        public ?string $uploadedAt,
    ) {}

    public static function fromModel(MediaItem $item, MediaLibrary $library): self
    {
        return new self(
            id: $item->getKey(),
            brandId: $item->brand_id,
            originalFilename: $item->original_filename,
            mimeType: $item->mime_type,
            sizeBytes: $item->size_bytes,
            altText: $item->alt_text,
            url: $library->urlFor($item->getKey()),
            thumbUrl: $library->urlFor($item->getKey(), 'thumb'),
            usageCount: $item->usages_count
                ?? MediaUsage::withoutBrandScope()->where('media_item_id', $item->getKey())->count(),
            variantsPending: $item->variants_generated_at === null,
            uploadedAt: $item->created_at?->format('Y-m-d H:i'),
        );
    }
}
