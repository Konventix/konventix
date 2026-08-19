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
    // `request()` is what BrandUrl reads scheme and port from; swapping the
    // bound instance is how a test says "this call is happening on that host".
    app()->instance('request', Request::create($url));
}

it('links to the primary host when a brand answers on several', function (): void {
    platformRequestFrom('http://admin.example/admin/content');

    $brand = Brand::factory()->create();
    $brand->domains()->create(['host' => 'legacy.example', 'is_primary' => false]);
    $brand->domains()->create(['host' => 'sxlan.example', 'is_primary' => true]);
    $brand->domains()->create(['host' => 'www.sxlan.example', 'is_primary' => false]);

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('http://sxlan.example');
});

it('carries the scheme and a non-default port from the current request', function (): void {
    // A developer on a nonstandard port needs a link that actually opens.
    platformRequestFrom('https://admin.example:8443/admin/content');

    $brand = Brand::factory()->withDomain('sxlan.example')->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('https://sxlan.example:8443');
});

it('omits the port when it is the scheme default', function (): void {
    platformRequestFrom('https://admin.example/admin/content');

    $brand = Brand::factory()->withDomain('sxlan.example')->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBe('https://sxlan.example');
});

it('has no link for a brand nobody has pointed a domain at', function (): void {
    platformRequestFrom('http://admin.example/admin/content');

    // A legal state: the brand exists, its DNS does not yet.
    $brand = Brand::factory()->create();

    expect(app(BrandUrl::class)->baseFor($brand))->toBeNull();
});

it('resolves a whole page of brands in one query', function (): void {
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
