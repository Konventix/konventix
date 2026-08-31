<?php

declare(strict_types=1);

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
