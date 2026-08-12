<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * ADR-0010: brand isolation must be a property of the persistence layer, not of
 * developer discipline. These tests exercise the mechanism itself against a
 * throwaway model, so they keep passing as real modules come and go.
 */
beforeEach(function (): void {
    Schema::create('scoping_probes', function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->ulid('brand_id')->index();
        $table->string('label');
        $table->timestamps();
    });
});

/** Stand-in for any brand-owned model. */
class ScopingProbe extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'scoping_probes';

    protected $fillable = ['label', 'brand_id'];
}

it('constrains queries to the brand in context', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();

    $context = app(BrandContext::class);

    $context->set($first);
    ScopingProbe::create(['label' => 'belongs to first']);

    $context->set($second);
    ScopingProbe::create(['label' => 'belongs to second']);

    expect(ScopingProbe::query()->pluck('label')->all())->toBe(['belongs to second']);

    $context->set($first);
    expect(ScopingProbe::query()->pluck('label')->all())->toBe(['belongs to first']);
});

it('stamps brand_id on create without being told', function (): void {
    $brand = Brand::factory()->create();
    app(BrandContext::class)->set($brand);

    $probe = ScopingProbe::create(['label' => 'unstamped']);

    expect($probe->brand_id)->toBe($brand->getKey());
});

it('refuses to create a brand-owned record with no brand in context', function (): void {
    app(BrandContext::class)->forget();

    ScopingProbe::create(['label' => 'orphan']);
})->throws(RuntimeException::class, 'No brand has been resolved');

it('exposes a named escape hatch that sees every brand', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();
    $context = app(BrandContext::class);

    $context->set($first);
    ScopingProbe::create(['label' => 'first']);
    $context->set($second);
    ScopingProbe::create(['label' => 'second']);

    expect(ScopingProbe::query()->count())->toBe(1)
        ->and(ScopingProbe::withoutBrandScope()->count())->toBe(2);
});

it('restores the previous brand after a scoped callback', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();
    $context = app(BrandContext::class);

    $context->set($first);

    $seen = $context->withBrand($second, fn (): ?string => $context->id());

    expect($seen)->toBe($second->getKey())
        ->and($context->id())->toBe($first->getKey());
});

it('does not constrain when no brand is resolved, so console tooling still works', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();
    $context = app(BrandContext::class);

    $context->set($first);
    ScopingProbe::create(['label' => 'first']);
    $context->set($second);
    ScopingProbe::create(['label' => 'second']);

    $context->forget();

    expect(ScopingProbe::query()->count())->toBe(2);
});
