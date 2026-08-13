<?php

declare(strict_types=1);

/**
 * The offline entrance client's shell and its two root-served assets
 * (ADR-0002's runtime carve-out).
 *
 * The client's own decision logic is tested in TypeScript
 * (`resources/js/entrance/lib/evaluate.test.ts`, run by `npm run test:js`) —
 * it runs in a browser and cannot be exercised from PHP. What PHP owns, and
 * what these tests cover, is that the shell is reachable without a session and
 * that the service worker is served from a path where it can actually claim
 * its scope.
 */
it('serves the client shell without requiring a session', function (): void {
    // A door tablet has no user to log in. The device authenticates itself to
    // /api/entrance/* with its own bearer token instead.
    $this->get('/entrance')
        ->assertOk()
        ->assertSee('id="entrance"', escape: false);
});

it('serves the shell with no brand or server state baked in', function (): void {
    // A scanner power-cycled at a venue with a dead uplink boots from cache,
    // so the shell must carry nothing it would need a server to produce.
    $response = $this->get('/entrance')->assertOk();

    expect($response->getContent())->not->toContain('brandTokens')
        ->and($response->getContent())->toContain('/entrance.webmanifest');
});

it('serves the service worker from the site root so it can claim /entrance', function (): void {
    // Vite writes it to public/build, but a worker may only control URLs at or
    // below its own path — from /build it could never precache the shell.
    if (! is_file(public_path('build/entrance-sw.js'))) {
        $this->markTestSkipped('Run `npm run build` first — the worker is a build artifact.');
    }

    $response = $this->get('/entrance-sw.js')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('javascript')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/entrance')
        // A stale worker keeps an old shell alive across a deploy.
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache');
});

it('serves the web manifest from the site root', function (): void {
    if (! is_file(public_path('build/entrance.webmanifest'))) {
        $this->markTestSkipped('Run `npm run build` first — the manifest is a build artifact.');
    }

    expect($this->get('/entrance.webmanifest')->assertOk()->headers->get('Content-Type'))
        ->toContain('manifest');
});

it('404s the build artifacts before a build rather than 500ing', function (): void {
    // Before the first `npm run build` there is nothing to serve, and that is
    // a missing file, not a server fault.
    $path = public_path('build/entrance-sw.js');

    if (is_file($path)) {
        $this->markTestSkipped('Build artifacts present; the missing-file path cannot be exercised here.');
    }

    $this->get('/entrance-sw.js')->assertNotFound();
});
