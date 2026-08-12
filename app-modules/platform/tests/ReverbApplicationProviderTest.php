<?php

declare(strict_types=1);

use Konventix\Platform\Models\Brand;
use Konventix\Platform\Realtime\BrandApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;

/**
 * ADR-0025: one shared Reverb server, one application per brand. The provider
 * turns brand rows into Reverb applications, so brand isolation rests on the
 * credentials and origins asserted here.
 */
it('provisions reverb credentials when a brand is created', function (): void {
    $brand = Brand::query()->create(['name' => 'SXLan', 'slug' => 'sxlan']);

    expect($brand->reverb_app_key)->toBeString()->not->toBe('')
        ->and($brand->reverb_app_secret)->toBeString()->not->toBe('');
});

it('gives every brand its own credentials', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();

    expect($first->reverb_app_key)->not->toBe($second->reverb_app_key)
        ->and($first->reverb_app_secret)->not->toBe($second->reverb_app_secret);
});

it('exposes a brand as a reverb application under its own credentials', function (): void {
    $brand = Brand::factory()->withDomain('sxlan.test')->create();

    $application = new BrandApplicationProvider()->findByKey((string) $brand->reverb_app_key);

    expect($application->id())->toBe($brand->getKey())
        ->and($application->key())->toBe($brand->reverb_app_key)
        ->and($application->secret())->toBe($brand->reverb_app_secret);
});

it('finds a brand application by its ulid', function (): void {
    $brand = Brand::factory()->create();

    expect(new BrandApplicationProvider()->findById($brand->getKey())->key())
        ->toBe($brand->reverb_app_key);
});

it('pins allowed origins to exactly the hosts the brand answers on', function (): void {
    $brand = Brand::factory()->create();
    $brand->domains()->create(['host' => 'sxlan.test', 'is_primary' => true]);
    $brand->domains()->create(['host' => 'www.sxlan.test', 'is_primary' => false]);

    Brand::factory()->withDomain('other.test')->create();

    $application = new BrandApplicationProvider()->findById($brand->getKey());

    expect($application->allowedOrigins())
        ->toBe(['sxlan.test', 'www.sxlan.test'])
        ->not->toContain('*');
});

it('gives a brand without domains an unmatchable origin list, never a wildcard', function (): void {
    $brand = Brand::factory()->create();

    expect(new BrandApplicationProvider()->findById($brand->getKey())->allowedOrigins())
        ->toBe([]);
});

it('rejects unknown applications', function (): void {
    Brand::factory()->create();

    new BrandApplicationProvider()->findByKey('no-such-key');
})->throws(InvalidApplication::class);

it('lists one application per brand', function (): void {
    // The localhost brand from TestCase::setUp() plus two more.
    Brand::factory()->count(2)->create();

    $applications = new BrandApplicationProvider()->all();

    expect($applications)->toHaveCount(3)
        ->and($applications->map->id()->all())
        ->toEqualCanonicalizing(Brand::query()->pluck('id')->all());
});
