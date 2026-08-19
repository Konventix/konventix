<?php

declare(strict_types=1);

use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operational half of the tooling: what is actually broken, and whether
 * there is a copy of the data somewhere else.
 *
 * @return array{0: User, 1: Brand}
 */
function healthOperator(array $capabilities): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.health.test'])->save();

    $brand = Brand::factory()->withDomain('brand.health.test')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Health Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$admin, $brand];
}

it('shows the health dashboard on the operator host', function (): void {
    [$admin] = healthOperator(['platform.access-admin', 'platform.view-diagnostics']);

    $this->actingAs($admin)
        ->get('http://admin.health.test/admin/health')
        ->assertOk();
});

it('refuses the health dashboard to console access alone', function (): void {
    [$admin] = healthOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.health.test/admin/health')
        ->assertForbidden();
});

it('does not serve the health dashboard on a brand host', function (): void {
    [$admin] = healthOperator(['platform.access-admin', 'platform.view-diagnostics']);

    $this->actingAs($admin)
        ->get('http://brand.health.test/admin/health')
        ->assertNotFound();
});

/**
 * A list of which services are currently failing, served to anyone who asks,
 * is reconnaissance rather than monitoring. With no secret configured the
 * route is not registered at all, which is a better default than registering
 * it and hoping nobody looks.
 */
it('does not publish the machine-readable health report without a secret', function (): void {
    expect(config('health.endpoint_secret'))->toBeEmpty();

    $this->get('http://brand.health.test/health-json')->assertNotFound();
});

it('serves the machine-readable report to a monitor holding the secret', function (): void {
    config(['health.endpoint_secret' => 'a-shared-secret']);

    // On a host belonging to no brand, which is the point: a monitor reaches
    // the application on the proxy's internal address, not on a brand domain.
    $this->get('http://internal-probe.test/health-json', ['X-Health-Secret' => 'a-shared-secret'])
        ->assertOk();
});

it('hides the machine-readable report from a wrong secret', function (): void {
    config(['health.endpoint_secret' => 'a-shared-secret']);

    // 404, not 401: a caller without the secret should not learn the endpoint
    // is here at all.
    $this->get('http://internal-probe.test/health-json', ['X-Health-Secret' => 'not-it'])
        ->assertNotFound();

    $this->get('http://internal-probe.test/health-json')->assertNotFound();
});

/**
 * `/up` proves PHP answered, which is the failure that never happens. It is
 * registered without the `web` group, so unlike everything else it survives a
 * host the application does not recognise — which is what lets the proxy health
 * check it on an internal address.
 */
it('answers the built-in health route on a host belonging to no brand', function (): void {
    $this->get('http://internal-probe.test/up')->assertOk();
});

it('backs up to a disk the application does not write to', function (): void {
    // A bucket is not a backup of itself: the destination must be nameable
    // separately from the media and invoice disks, or there is no off-site copy.
    expect(config('backup.backup.destination.disks'))->toBe([env('BACKUP_DESTINATION_DISK', 'local')])
        ->and(config('backup.backup.destination.disks'))->not->toContain(config('filesystems.media_disk'))
        ->and(config('backup.backup.destination.disks'))->not->toContain(config('filesystems.invoices_disk'));
});

it('installs sentry inert, so nothing leaves a development machine', function (): void {
    // Installed and wired into the exception handler, but with no DSN it
    // reports nowhere — which is the correct state until the data-protection
    // question behind a production DSN has an answer.
    expect(config('sentry.dsn'))->toBeNull()
        ->and(config('sentry.send_default_pii'))->toBeFalse();
});
