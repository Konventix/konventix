<?php

declare(strict_types=1);

use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission;

it('runs pending migrations when auto migration is enabled', function () {
    config(['database.auto_migrate' => true]);

    $this->artisan('konventix:migrate')
        ->doesntExpectOutputToContain('skipping automatic migration')
        ->assertSuccessful();
});

it('skips migration when the operator disabled it via AUTO_MIGRATE', function () {
    config(['database.auto_migrate' => false]);

    $this->artisan('konventix:migrate')
        ->expectsOutputToContain('AUTO_MIGRATE=false — skipping automatic migration.')
        ->assertSuccessful();
});

test('konventix:migrate syncs permissions after migrating', function () {
    Permission::query()->delete();

    $this->artisan('konventix:migrate')->assertSuccessful();

    expect(Permission::query()->count())->toBeGreaterThan(0);
});

test('konventix:migrate skips everything when auto migration is off', function () {
    config()->set('database.auto_migrate', false);
    Permission::query()->delete();

    $this->artisan('konventix:migrate')
        ->expectsOutputToContain('AUTO_MIGRATE=false')
        ->assertSuccessful();

    expect(Permission::query()->count())->toBe(0);
});

// #287: a brand with no active entrance signing key answers 500 on every
// ticket download, and brands built by `konventix:install` or a seeder
// never pass through `CreateBrand`, so nothing announces their creation.
// The backfill rides the migrator role rather than adding a deploy step.
test('konventix:migrate mints a missing entrance signing key and leaves an existing one alone', function () {
    config()->set('database.auto_migrate', true);

    $without = Brand::factory()->create(['slug' => 'migrate-no-key']);
    $with = Brand::factory()->create(['slug' => 'migrate-has-key']);

    $kept = app(RotateEntranceSigningKey::class)->handle($with->getKey());

    $this->artisan('konventix:migrate')->assertSuccessful();

    $active = fn (string $brandId) => EntranceSigningKey::withoutBrandScope()
        ->where('brand_id', $brandId)
        ->whereNull('retired_at')
        ->pluck('id');

    expect($active($without->getKey()))->toHaveCount(1)
        ->and($active($with->getKey())->all())->toBe([$kept->getKey()]);
});
