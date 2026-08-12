<?php

declare(strict_types=1);

namespace Konventix\Platform\Media;

use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;

/**
 * The sanctioned read/reference API of the media library (ADR-0035):
 * consumers hold a media ULID and call this service — "Platform's own read
 * API is the only sanctioned way to turn a media ULID into something
 * renderable." No consumer ever sees a disk or path.
 *
 * Lookups run `withoutBrandScope` deliberately: a consumer passes the ULID
 * it stored, and that ULID already implies the brand — resolution must not
 * depend on which brand (or operator host) is ambient when rendering
 * happens (queued mail, admin previews). Stateless; safe under Octane.
 */
class MediaLibrary
{
    /**
     * Resolve a media ULID to a public URL, optionally for a named variant.
     * Unknown ULIDs and unknown variants resolve to null — a stale
     * reference renders as a missing image, not an exception (mirrors the
     * block renderer's graceful-degradation stance).
     */
    public function urlFor(string $mediaId, ?string $variant = null): ?string
    {
        $item = MediaItem::withoutBrandScope()->find($mediaId);

        if ($item === null) {
            return null;
        }

        $path = $variant === null
            ? $item->path
            : ($item->variants[$variant] ?? null);

        if ($path === null) {
            return null;
        }

        return Storage::disk($item->disk)->url($path);
    }

    /**
     * Record that a module's entity references a media item. Idempotent —
     * re-recording the same reference is a no-op.
     */
    public function recordUsage(string $mediaId, string $module, string $entityType, string $entityId): void
    {
        $item = MediaItem::withoutBrandScope()->findOrFail($mediaId);

        $exists = MediaUsage::withoutBrandScope()
            ->where('media_item_id', $item->id)
            ->where('module', $module)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->exists();

        if ($exists) {
            return;
        }

        $usage = new MediaUsage([
            'media_item_id' => $item->id,
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
        // The usage lives in the item's brand, not the ambient context.
        $usage->brand_id = $item->brand_id;
        $usage->save();
    }

    /**
     * Release a previously recorded reference. Unknown references are a
     * no-op — release must be safe to call from delete paths that cannot
     * know whether the reference was ever recorded.
     */
    public function releaseUsage(string $mediaId, string $module, string $entityType, string $entityId): void
    {
        MediaUsage::withoutBrandScope()
            ->where('media_item_id', $mediaId)
            ->where('module', $module)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->delete();
    }
}
