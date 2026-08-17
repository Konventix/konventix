<?php

declare(strict_types=1);

namespace Konventix\Platform\Media;

use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;
use Konventix\Platform\Storage\DiskResolver;

/**
 * The sanctioned read/reference API of the media library (ADR-0035):
 * consumers hold a media ULID and call this service — "Platform's own read
 * API is the only sanctioned way to turn a media ULID into something
 * renderable." No consumer ever sees a disk or path.
 *
 * Lookups run `withoutBrandScope` deliberately: a consumer passes the ULID
 * it stored, and that ULID already implies the brand — resolution must not
 * depend on which brand (or operator host) is ambient when rendering
 * happens (queued mail, admin previews).
 *
 * Disk resolution goes through {@see DiskResolver}, which is request-scoped
 * because a brand may bring its own bucket (ADR-0043 point 6) — so an
 * instance of this class must be resolved per request too, never captured at
 * boot.
 */
class MediaLibrary
{
    public function __construct(private readonly DiskResolver $disks) {}

    /**
     * Resolve a media ULID to a public URL, optionally for a named variant.
     * Unknown ULIDs and unknown variants resolve to null — a stale
     * reference renders as a missing image, not an exception (mirrors the
     * block renderer's graceful-degradation stance).
     */
    public function urlFor(string $mediaId, ?string $variant = null): ?string
    {
        return $this->referenceFor($mediaId, $variant)['url'] ?? null;
    }

    /**
     * Resolve a media ULID to everything a renderer needs to display it: the
     * URL plus the item's own alt text.
     *
     * One method rather than `urlFor()` + an `altText()` companion because a
     * consumer that renders an image needs both, and two calls would mean two
     * lookups of the same row for every image on a page.
     *
     * Same graceful degradation as everything else here: an unknown ULID or
     * unknown variant resolves to null.
     *
     * @return array{url: string, altText: string|null}|null
     */
    public function referenceFor(string $mediaId, ?string $variant = null): ?array
    {
        $item = MediaItem::withoutBrandScope()->find($mediaId);

        if ($item === null) {
            return null;
        }

        $path = $this->pathFor($item, $variant);

        if ($path === null) {
            return null;
        }

        return [
            'url' => $this->deliveryUrl($item, $path, $variant),
            'altText' => $item->alt_text,
        ];
    }

    /**
     * Where a reader fetches this object from (ADR-0043).
     *
     * A disk marked `public_direct` is readable without the application —
     * the local disk through the `public/storage` symlink, an object store
     * through a public bucket or CDN — so its own URL is handed out and the
     * bytes never touch a worker. Anything else is served through the
     * application's media route.
     *
     * The flag is deployment configuration and is never inferred from the
     * driver: a bucket's readability is a property of how the operator
     * created it, and guessing wrong yields either broken images or needless
     * proxying. `storage:doctor` is what checks the claim.
     *
     * Resolution stays network-free either way — this builds a URL, it never
     * asks the store whether the object is there.
     */
    private function deliveryUrl(MediaItem $item, string $path, ?string $variant): string
    {
        if ($this->disks->isPublicDirect($item->disk)) {
            return $this->disks->filesystem($item->disk)->url($path);
        }

        return route('platform.media.show', array_filter([
            'media' => $item->getKey(),
            'variant' => $variant,
        ]));
    }

    /**
     * Resolve a media ULID to a `data:` URI containing the asset's bytes, for
     * renderers that cannot fetch a URL.
     *
     * This exists because server-side PDF rendering cannot use `urlFor()`:
     * dompdf ships with remote fetching disabled, so an `http://` image
     * silently renders as nothing. Enabling remote fetching instead would let
     * any stored media reference trigger an outbound request from the server
     * at render time, which is a much worse trade than inlining bytes.
     *
     * It is a method *here* rather than a `Storage::disk(...)->path()` call in
     * the consuming module because this class is the media library's only
     * sanctioned doorway (ADR-0035) — "no consumer ever sees a disk or path".
     * Widening that doorway keeps the rule intact; reaching around it would
     * not.
     *
     * Same graceful degradation as `urlFor()`: an unknown ULID, unknown
     * variant, or missing file resolves to null so a stale reference renders
     * as a missing image rather than failing the document.
     */
    public function dataUriFor(string $mediaId, ?string $variant = null): ?string
    {
        $item = MediaItem::withoutBrandScope()->find($mediaId);

        if ($item === null) {
            return null;
        }

        $path = $this->pathFor($item, $variant);

        if ($path === null) {
            return null;
        }

        $disk = $this->disks->filesystem($item->disk);

        if (! $disk->exists($path)) {
            return null;
        }

        return sprintf(
            'data:%s;base64,%s',
            $disk->mimeType($path) ?: 'application/octet-stream',
            base64_encode($disk->get($path) ?? ''),
        );
    }

    /**
     * The stored path behind an item's variant, or the original when no
     * variant is asked for. Null means the item has no such variant.
     */
    private function pathFor(MediaItem $item, ?string $variant): ?string
    {
        if ($variant === null) {
            return $item->path;
        }

        return $item->variants[$variant] ?? null;
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
