<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0029: the operator console resolves no brand, so anything that wants to
 * link a reader at a brand's own site has to be told the host explicitly.
 * This is where that lookup is proved.
 *
 * Helper name prefixed with the module: Pest helpers are process-global
 * across every module's test files (.ai/rules/tests.md).
 */
function platformRequestFrom(string $url): void
{
    // A bound request must NOT change the answer any more (#225); these tests
    // keep binding one so the request and the queue context can be compared.
    app()->instance('request', Request::create($url));
}

/**
 * The origin every brand link is built on comes from `APP_URL` (#225), so a
 * test says "this deployment answers there" by setting that.
 */
function platformAppUrl(string $url): void
{
    config()->set('app.url', $url);
}

it('links to the primary host when a brand answers on several', function (): void {
    platformAppUrl('http://admin.example');
    platformRequestFrom('http://admin.example/admin/content');

    $brand = Brand::factory()->create();
    $brand->domains()->create(['host' => 'legacy.example', 'is_primary' => false]);
    $brand->domains()->create(['host' => 'sxlan.example', 'is_primary' => true]);
    $brand->domains()->create(['host' => 'www.sxlan.example', 'is_primary' => false]);

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('http://sxlan.example');
});

it('carries the scheme and a non-default port from the configured app URL', function (): void {
    // A developer on a nonstandard port needs a link that actually opens.
    platformAppUrl('https://admin.example:8443');
    platformRequestFrom('https://admin.example:8443/admin/content');

    $brand = Brand::factory()->withDomain('sxlan.example')->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('https://sxlan.example:8443');
});

it('omits the port when it is the scheme default', function (): void {
    platformAppUrl('https://admin.example');
    platformRequestFrom('https://admin.example/admin/content');

    $brand = Brand::factory()->withDomain('sxlan.example')->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('https://sxlan.example');
});

it('omits a port APP_URL spells out when it is the scheme default', function (): void {
    // `https://…:443` and `http://…:80` are the same origins as without the
    // port, and a developer who mirrors compose's `APP_PORT` default writes
    // the second one. A link that says `:443` out loud is not wrong, it is
    // just not what anybody wrote down (#225 review finding 1).
    platformAppUrl('https://admin.example:443');

    $brand = Brand::factory()->withDomain('sxlan.example')->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('https://sxlan.example');

    platformAppUrl('http://admin.example:80');

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('http://sxlan.example');
});

it('builds an https link from a queue job, where no request is bound', function (): void {
    // #225: a mail rendered inside a queued job used to come out on http://
    // because `request()` hands back a synthetic request with no HTTPS
    // indicator. The origin is a property of the deployment, not of whatever
    // request happens to be in flight, so it comes from APP_URL.
    platformAppUrl('https://admin.dev.konventix.de');

    $brand = Brand::factory()->withDomain('sxlan.dev.konventix.de')->create();

    // Exactly the state a queue worker is in: nothing bound under `request`.
    app()->forgetInstance('request');

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('https://sxlan.dev.konventix.de');
});

it('answers the same in a request as in a queue job', function (): void {
    platformAppUrl('https://admin.dev.konventix.de');

    $brand = Brand::factory()->withDomain('sxlan.dev.konventix.de')->create();

    // The proxy terminates TLS, so the request the worker sees is plain http;
    // the link it prints must not inherit that (#225).
    platformRequestFrom('http://admin.dev.konventix.de/admin/content');
    $inRequest = app(BrandUrl::class)->baseFor($brand);

    app()->forgetInstance('request');
    $inJob = app(BrandUrl::class)->baseFor($brand);

    expect($inRequest)->toBe('https://sxlan.dev.konventix.de')
        ->and($inJob)->toBe($inRequest);
});

it('has no link for a brand nobody has pointed a domain at', function (): void {
    platformAppUrl('http://admin.example');
    platformRequestFrom('http://admin.example/admin/content');

    // A legal state: the brand exists, its DNS does not yet.
    $brand = Brand::factory()->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBeNull();
});

it('resolves a whole page of brands in one query', function (): void {
    platformAppUrl('http://admin.example');
    platformRequestFrom('http://admin.example/admin/content');

    $first = Brand::factory()->withDomain('one.example')->create();
    $second = Brand::factory()->withDomain('two.example')->create();
    $domainless = Brand::factory()->create();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $bases = app(BrandUrl::class)->basesFor(new Collection([$first, $second, $domainless]));

    // One lookup for the page: a list of entries must not turn into a domain
    // query per row.
    expect($queries)->toBe(1)
        ->and($bases)->toBe([
            $first->getKey() => 'http://one.example',
            $second->getKey() => 'http://two.example',
        ]);
});
