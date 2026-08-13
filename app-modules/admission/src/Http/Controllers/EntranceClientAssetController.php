<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the entrance client's service worker and web manifest **from the
 * site root**.
 *
 * Vite writes both into `public/build/`, but a service worker may only control
 * URLs at or below its own path — one served from `/build/entrance-sw.js`
 * could never claim `/entrance`, so the offline shell would never be
 * precached and a power-cycled scanner with no uplink would show a blank page.
 * Serving the same built file from `/entrance-sw.js` gives it the scope it
 * needs without a second build step or a `Service-Worker-Allowed` header.
 *
 * A missing file 404s rather than throwing: before the first `npm run build`
 * there is nothing to serve, and that should not be a 500.
 */
final class EntranceClientAssetController
{
    public function serviceWorker(): Response
    {
        return $this->serve('entrance-sw.js', 'application/javascript');
    }

    public function manifest(): Response
    {
        return $this->serve('entrance.webmanifest', 'application/manifest+json');
    }

    private function serve(string $file, string $contentType): Response
    {
        $path = public_path('build/'.$file);

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $contentType,
            // The worker must be re-fetched rather than served from a stale
            // HTTP cache, or a device keeps an old shell after a deploy.
            'Cache-Control' => 'no-cache',
            // Lets a worker served from the root control /entrance even if it
            // is ever moved back under a subdirectory.
            'Service-Worker-Allowed' => '/entrance',
        ]);
    }
}
