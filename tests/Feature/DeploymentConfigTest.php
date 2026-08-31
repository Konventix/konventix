<?php

declare(strict_types=1);

/**
 * ADR-0049: deployment switches ride in as env-backed config. These tests
 * pin the keys and their dev-preserving defaults — the chart overrides the
 * env, and a renamed key would silently strand every deployment on the
 * default.
 */
test('inertia ssr is env-switchable with dev-preserving defaults', function () {
    expect(config('inertia.ssr.enabled'))->toBeTrue()
        ->and(config('inertia.ssr.url'))->toBe('http://127.0.0.1:13714');
});
