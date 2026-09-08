<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers;

use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Storage\DiskResolver;
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
    /**
     * Every spelling of SVG that has ever been written into `mime_type`:
     * `image/svg+xml` is what browsers and PHP's finfo agree on, `image/svg`
     * is what some older uploaders sent.
     *
     * @var list<string>
     */
    private const SVG_MIME_TYPES = ['image/svg+xml', 'image/svg'];

    public function __invoke(DiskResolver $disks, string $media, ?string $variant = null): StreamedResponse
    {
        // Cross-brand by ULID like the rest of the library: the ULID implies
        // the brand, and rendering happens wherever it happens.
        $item = MediaItem::withoutBrandScope()->find($media);

        abort_if($item === null, 404);

        $path = $variant === null
            ? $item->path
            : ($item->variants[$variant] ?? null);

        abort_if($path === null, 404);

        abort_unless($disks->exists($item->disk), 404);

        $disk = $disks->filesystem($item->disk);

        abort_unless($disk->exists($path), 404);

        // An SVG is an executable document, and this route answers from the
        // brand's own origin without authentication — inline delivery would
        // be stored XSS (#252). New SVG uploads are refused outright
        // (MediaAdminController), so this only ever fires for a library that
        // already held one: it leaves as an opaque download instead of
        // anything a browser will parse. `nosniff` is restated here rather
        // than left to the global SecurityHeaders middleware (#251), because
        // this is the one response where sniffing is the whole attack.
        if (in_array(strtolower((string) $item->mime_type), self::SVG_MIME_TYPES, true)) {
            return $disk->response($path, $item->original_filename, [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ], 'attachment');
        }

        // Stored keys are never overwritten (ADR-0043), so a year is safe and
        // a re-upload arrives under a new ULID rather than a stale cache.
        return $disk->response($path, $item->original_filename, [
            'Content-Type' => $item->mime_type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], 'inline');
    }
}
