<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Models\MediaItem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a media item's bytes through the application (ADR-0043), for disks
 * that are not `public_direct`: object storage the operator kept private, or
 * a local deployment without the `public/storage` symlink.
 *
 * Deliberately unauthenticated and brand-agnostic, and registered outside the
 * `web` group by `PlatformServiceProvider` for that reason: media addressed
 * here is the same media anyone can already fetch from a public bucket, the
 * key is an opaque ULID, and the operator console needs the very same URL to
 * work on the admin host — where `ResolveBrand` would 404 it (ADR-0029).
 *
 * Private artifacts do NOT come through here. Invoices and export archives
 * are served by their own module's authenticated, policy-checked controllers
 * (ADR-0043 point 4).
 */
class MediaFileController
{
    public function __invoke(string $media, ?string $variant = null): StreamedResponse
    {
        // Cross-brand by ULID like the rest of the library: the ULID implies
        // the brand, and rendering happens wherever it happens.
        $item = MediaItem::withoutBrandScope()->find($media);

        abort_if($item === null, 404);

        $path = $variant === null
            ? $item->path
            : ($item->variants[$variant] ?? null);

        abort_if($path === null, 404);

        $disk = Storage::disk($item->disk);

        abort_unless($disk->exists($path), 404);

        // Stored keys are never overwritten (ADR-0043), so a year is safe and
        // a re-upload arrives under a new ULID rather than a stale cache.
        return $disk->response($path, $item->original_filename, [
            'Content-Type' => $item->mime_type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], 'inline');
    }
}
