<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Spatie\Permission\PermissionRegistrar;

afterEach(function () {
    unset($_ENV['KONVENTIX_ADMIN_PASSWORD']);
});

function installArguments(): array
{
    return [
        '--operator-name' => 'Acme Events GmbH',
        '--admin-host' => 'admin.acme.test',
        '--admin-email' => 'root@acme.test',
        '--brand-name' => 'Acme Con',
        '--brand-domain' => 'con.acme.test',
    ];
}

test('install creates operator, admin, role and brand, and is idempotent', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';

    $this->artisan('konventix:install', installArguments())->assertSuccessful();
    $this->artisan('konventix:install', installArguments())->assertSuccessful();

    $operator = Operator::query()->firstWhere('name', 'Acme Events GmbH');
    expect(Operator::query()->where('name', 'Acme Events GmbH')->count())->toBe(1)
        ->and($operator->admin_host)->toBe('admin.acme.test');

    $admin = User::query()->firstWhere('email', 'root@acme.test');
    expect($admin)->not->toBeNull()
        ->and($admin->email_verified_at)->not->toBeNull();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    expect($admin->hasRole('Operator Admin'))->toBeTrue();
    $registrar->setPermissionsTeamId(null);

    // Brand is not itself brand-scoped (see its docblock), so a plain query
    // finds it — there is no withoutBrandScope() escape hatch to reach for.
    $brand = Brand::query()->firstWhere('slug', 'acme-con');
    expect($brand)->not->toBeNull()
        ->and($brand->reverb_app_key)->not->toBeNull()
        ->and($brand->domains()->pluck('host')->all())->toBe(['con.acme.test']);
});

test('install without brand flags creates no brand', function () {
    $_ENV['KONVENTIX_ADMIN_PASSWORD'] = 'first-run-secret';
    $arguments = installArguments();
    unset($arguments['--brand-name'], $arguments['--brand-domain']);

    $this->artisan('konventix:install', $arguments)->assertSuccessful();

    expect(Brand::query()->where('slug', 'acme-con')->exists())->toBeFalse();
});

test('install refuses to create the admin without a password in the environment', function () {
    $this->artisan('konventix:install', installArguments())->assertFailed();

    expect(User::query()->where('email', 'root@acme.test')->exists())->toBeFalse();
});

test('install refuses to run without the required options', function () {
    $this->artisan('konventix:install')->assertFailed();
});
