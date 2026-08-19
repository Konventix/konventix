<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Http\Middleware\ResolveBrand;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0001: brand is resolved from the request host, and a host that matches no
 * brand is a 404 rather than an unscoped request. These tests drive the real
 * middleware over a throwaway route, so they assert on what a request actually
 * sees rather than on how the lookup is written.
 */
beforeEach(function (): void {
    Route::middleware(ResolveBrand::class)
        ->get('/brand-probe', fn (): string => app(BrandContext::class)->currentOrFail()->name);
});

it('resolves the brand registered for the request host', function (): void {
    $brand = Brand::factory()->withDomain('example.test')->create();

    $this->get('http://example.test/brand-probe')
        ->assertOk()
        ->assertSee($brand->name, escape: false);

    expect(app(BrandContext::class)->id())->toBe($brand->getKey());
});

it('404s a host no brand answers on', function (): void {
    Brand::factory()->withDomain('known.test')->create();

    $this->get('http://stranger.test/brand-probe')->assertNotFound();

    expect(app(BrandContext::class)->hasBrand())->toBeFalse();
});

it('404s when no brand exists at all', function (): void {
    $this->get('http://example.test/brand-probe')->assertNotFound();
});

it('gives each host its own brand', function (): void {
    $first = Brand::factory()->withDomain('first.test')->create();
    $second = Brand::factory()->withDomain('second.test')->create();

    $this->get('http://first.test/brand-probe')->assertOk()->assertSee($first->name, escape: false);
    expect(app(BrandContext::class)->id())->toBe($first->getKey());

    $this->get('http://second.test/brand-probe')->assertOk()->assertSee($second->name, escape: false);
    expect(app(BrandContext::class)->id())->toBe($second->getKey());
});

it('resolves the brand owning the host, not the first brand in the table', function (): void {
    $decoy = Brand::factory()->withDomain('decoy.test')->create();
    $wanted = Brand::factory()->withDomain('wanted.test')->create();

    // The wanted brand must not be the first row (the base TestCase's
    // localhost brand and the decoy both precede it), or the test proves
    // nothing about host-based lookup.
    //
    // Ordered explicitly: a bare `first()` has no ORDER BY, so it returned
    // whatever Postgres happened to hand back first and this precondition
    // passed by luck. ULIDs sort chronologically, so ordering by id is
    // literally "the first brand in the table" as this test means it.
    expect(Brand::query()->orderBy('id')->first()?->getKey())->not->toBe($wanted->getKey())
        ->and($decoy->getKey())->not->toBe($wanted->getKey());

    $this->get('http://wanted.test/brand-probe')->assertOk()->assertSee($wanted->name, escape: false);

    expect(app(BrandContext::class)->id())->toBe($wanted->getKey());
});

it('resolves the same brand from any of its hosts', function (): void {
    $brand = Brand::factory()->create();
    $brand->domains()->create(['host' => 'apex.test', 'is_primary' => true]);
    $brand->domains()->create(['host' => 'www.apex.test', 'is_primary' => false]);

    foreach (['apex.test', 'www.apex.test'] as $host) {
        $this->get("http://{$host}/brand-probe")->assertOk()->assertSee($brand->name, escape: false);
        expect(app(BrandContext::class)->id())->toBe($brand->getKey());
    }
});

it('leaves an unmatched host with no brand in context, so nothing downstream runs unscoped', function (): void {
    $brand = Brand::factory()->withDomain('known.test')->create();

    $this->get('http://known.test/brand-probe')->assertOk();
    expect(app(BrandContext::class)->id())->toBe($brand->getKey());

    app(BrandContext::class)->forget();

    $this->get('http://stranger.test/brand-probe')->assertNotFound();
    expect(app(BrandContext::class)->hasBrand())->toBeFalse();
});
