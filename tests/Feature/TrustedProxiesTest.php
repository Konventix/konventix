<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Http\Middleware\ResolveBrand;
use Konventix\Platform\Models\Brand;

/**
 * Konventix sits behind a reverse proxy that terminates TLS and is not part of
 * this application. Everything the app knows about what the visitor asked for
 * therefore arrives in `X-Forwarded-*` headers, and believing them is not
 * optional here: brands are resolved from the request host (ADR-0001), so a
 * discarded `X-Forwarded-Host` resolves the proxy's own hostname instead of
 * the brand the visitor typed.
 *
 * Laravel's test lifecycle flushes `TrustProxies`' static state between tests,
 * so bootstrap/app.php's configuration is deliberately not in play here — each
 * test states the trust it is exercising.
 */
beforeEach(function (): void {
    Route::middleware(ResolveBrand::class)->get('/proxy-probe', fn (): array => [
        'brand' => app(BrandContext::class)->currentOrFail()->name,
        'secure' => request()->isSecure(),
        'host' => request()->getHost(),
        'url' => url('/proxy-probe'),
    ]);
});

it('resolves the brand from the host the visitor asked for, not the proxy', function (): void {
    TrustProxies::at('127.0.0.1');

    $brand = Brand::factory()->withDomain('sxlan.test')->create();

    // What a proxied request actually looks like: the connection is to the
    // internal app host, and the visitor's host rides in the header.
    $this->get('http://app-internal.test/proxy-probe', [
        'X-Forwarded-Host' => 'sxlan.test',
        'X-Forwarded-Proto' => 'https',
    ])
        ->assertOk()
        ->assertJson([
            'brand' => $brand->name,
            'host' => 'sxlan.test',
        ]);
});

/**
 * `isSecure()` is what decides whether session cookies get the `secure` flag
 * and which scheme signed URLs are built and verified against — Admission's
 * entrance tokens and Compliance's export downloads both depend on it.
 */
it('knows the request was https even though it arrived as http', function (): void {
    TrustProxies::at('127.0.0.1');

    Brand::factory()->withDomain('sxlan.test')->create();

    $this->get('http://app-internal.test/proxy-probe', [
        'X-Forwarded-Host' => 'sxlan.test',
        'X-Forwarded-Proto' => 'https',
    ])
        ->assertOk()
        ->assertJson([
            'secure' => true,
            'url' => 'https://sxlan.test/proxy-probe',
        ]);
});

it('carries a non-default forwarded port into generated URLs', function (): void {
    TrustProxies::at('127.0.0.1');

    Brand::factory()->withDomain('sxlan.test')->create();

    $this->get('http://app-internal.test/proxy-probe', [
        'X-Forwarded-Host' => 'sxlan.test',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '8443',
    ])
        ->assertOk()
        ->assertJson(['url' => 'https://sxlan.test:8443/proxy-probe']);
});

/**
 * The reason the trusted list is not `*` by default. Brand resolution and
 * session cookie naming both read the host, so a caller that can reach the
 * application directly must not be able to choose which brand it is talking
 * to by asserting a header.
 */
it('ignores forwarded headers from a client that is not a trusted proxy', function (): void {
    TrustProxies::at('10.99.99.99');

    Brand::factory()->withDomain('sxlan.test')->create();
    $direct = Brand::factory()->withDomain('app-internal.test')->create();

    $this->get('http://app-internal.test/proxy-probe', [
        'X-Forwarded-Host' => 'sxlan.test',
        'X-Forwarded-Proto' => 'https',
    ])
        ->assertOk()
        ->assertJson([
            'brand' => $direct->name,
            'host' => 'app-internal.test',
            'secure' => false,
        ]);
});

it('still 404s when the forwarded host belongs to no brand', function (): void {
    TrustProxies::at('127.0.0.1');

    Brand::factory()->withDomain('sxlan.test')->create();

    // The forwarded host is believed, and then judged on its merits — trusting
    // the proxy is not the same as trusting the name it forwards.
    $this->get('http://app-internal.test/proxy-probe', [
        'X-Forwarded-Host' => 'stranger.test',
    ])->assertNotFound();
});

/**
 * The one test that exercises the shipped configuration rather than a trust
 * this test set up itself.
 *
 * `TrustProxies` falls back to `config('trustedproxy.proxies')` when no static
 * trust is set — and the test lifecycle flushes that static — so omitting the
 * `at()` call above is what makes this request go through
 * config/trustedproxy.php. The suite runs from loopback, which the shipped
 * default trusts, exactly as a proxy on the container network would be.
 *
 * Without that config file this test fails: forwarded headers are ignored
 * entirely when no proxy is trusted, which is the state the application was in
 * before this change.
 */
it('honours forwarded headers using the applications own shipped config', function (): void {
    $brand = Brand::factory()->withDomain('sxlan.test')->create();

    $this->get('http://app-internal.test/proxy-probe', [
        'X-Forwarded-Host' => 'sxlan.test',
        'X-Forwarded-Proto' => 'https',
    ])
        ->assertOk()
        ->assertJson([
            'brand' => $brand->name,
            'host' => 'sxlan.test',
            'secure' => true,
        ]);
});

it('ships a default trusted list that is not a blanket wildcard', function (): void {
    // A `*` default would trust every caller's X-Forwarded-Host the moment
    // somebody could reach the app without going through the proxy.
    expect(config('trustedproxy.proxies'))->toBeString()
        ->and(config('trustedproxy.proxies'))->not->toBe('*')
        ->and(config('trustedproxy.proxies'))->toContain('10.0.0.0/8');
});
