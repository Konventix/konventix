<?php

declare(strict_types=1);

use Konventix\Platform\Models\Operator;

/**
 * #251: the application sends its own security response headers rather than
 * relying on whatever the ingress controller happens to add. The middleware
 * is global, not `web`-only, because the surfaces that need it most sit
 * outside that group: the unauthenticated media route (#252), the PSP
 * webhooks and the health endpoints.
 *
 * A full script CSP is deliberately out of scope — `resources/views/app.blade.php`
 * carries two inline blocks (the appearance probe and the brand token style),
 * so `script-src` needs nonce work that is its own piece. What ships here is
 * the part that needs no nonce: framing, plugins and base URI.
 */
$expectedHeaders = [
    'X-Content-Type-Options' => 'nosniff',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'X-Frame-Options' => 'DENY',
    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    'Content-Security-Policy' => "frame-ancestors 'none'; object-src 'none'; base-uri 'self'",
];

it('sends the security headers on a public brand page', function () use ($expectedHeaders): void {
    $this->withBrand();

    $response = $this->get('/')->assertOk();

    foreach ($expectedHeaders as $header => $value) {
        $response->assertHeader($header, $value);
    }
});

it('sends the security headers on the operator console', function () use ($expectedHeaders): void {
    $this->withBrand();

    Operator::factory()->create()->forceFill(['admin_host' => 'admin.headers.test'])->save();

    // Unauthenticated, so this redirects to login — the headers are set by a
    // global middleware and therefore ride on the redirect too.
    $response = $this->get('http://admin.headers.test/admin');

    foreach ($expectedHeaders as $header => $value) {
        $response->assertHeader($header, $value);
    }
});

/**
 * The stateless `api` surface carries neither session nor CSRF middleware, so
 * a header appended to the `web` group alone would never reach it.
 */
it('sends the security headers on the psp webhook route', function () use ($expectedHeaders): void {
    $this->withBrand();

    // No signature, so the handler rejects it — the headers are on the way
    // out regardless of what the controller decided.
    $response = $this->postJson(route('api.commerce.webhooks.stripe'), []);

    foreach ($expectedHeaders as $header => $value) {
        $response->assertHeader($header, $value);
    }
});

it('sends the security headers on the health endpoint outside every group', function () use ($expectedHeaders): void {
    $response = $this->get('/up');

    foreach ($expectedHeaders as $header => $value) {
        $response->assertHeader($header, $value);
    }
});

/**
 * HSTS is the one header that must not be sent unconditionally: a browser
 * that receives it over plain HTTP is told to pin a scheme the local
 * development stack does not serve.
 */
it('sends hsts only when the request arrived over tls', function (): void {
    $this->withBrand();

    $this->get('http://localhost/')->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends hsts on a secure request', function (): void {
    $this->withBrand();

    $this->get('https://localhost/')->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
