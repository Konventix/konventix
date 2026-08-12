<?php

declare(strict_types=1);

use Konventix\Platform\Models\Brand;

/**
 * ADR-0001: on-demand TLS must only mint certificates for hosts a brand
 * answers on. Caddy's `ask` endpoint is the gate.
 */
it('approves a host a brand answers on', function (): void {
    Brand::factory()->withDomain('sxlan.test')->create();

    $this->get('/caddy/ask?domain=sxlan.test')->assertOk();
});

it('refuses a host no brand answers on', function (): void {
    Brand::factory()->withDomain('known.test')->create();

    $this->get('/caddy/ask?domain=stranger.test')->assertNotFound();
});

it('refuses an empty ask', function (): void {
    $this->get('/caddy/ask')->assertNotFound();
});

it('matches hosts case-insensitively', function (): void {
    Brand::factory()->withDomain('sxlan.test')->create();

    $this->get('/caddy/ask?domain=SXLan.TEST')->assertOk();
});
