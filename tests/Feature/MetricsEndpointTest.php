<?php

declare(strict_types=1);

/**
 * The metrics endpoint is a diagnostics surface like health-json (ADR-0059):
 * 404 without the shared secret, so nobody learns it exists; the secret is
 * accepted as the health header or as a bearer token, because vmagent sends
 * credentials as `Authorization: Bearer`. GitLab #173.
 */
beforeEach(function (): void {
    config()->set('health.endpoint_secret', 'metrics-test-secret');
});

it('answers 404 without the secret', function (): void {
    $this->get('/metrics')->assertNotFound();
});

it('answers 404 with a wrong bearer token', function (): void {
    $this->withHeader('Authorization', 'Bearer nope')->get('/metrics')->assertNotFound();
});

it('renders the registry for the health header', function (): void {
    $this->withHeader('X-Health-Secret', 'metrics-test-secret')
        ->get('/metrics')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=UTF-8')
        ->assertSee('# TYPE konventix_build_info gauge', escape: false)
        ->assertSee('konventix_build_info{version="'.config('app.version').'"} 1', escape: false);
});

it('renders the registry for a bearer token', function (): void {
    $this->withHeader('Authorization', 'Bearer metrics-test-secret')
        ->get('/metrics')
        ->assertOk()
        ->assertSee('konventix_build_info', escape: false);
});

it('answers 404 when no secret is configured at all', function (): void {
    config()->set('health.endpoint_secret', '');

    $this->withHeader('Authorization', 'Bearer ')->get('/metrics')->assertNotFound();
});
