<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Slots\SlotRegistry;
use Laravel\Telescope\Telescope;
use Spatie\Permission\PermissionRegistrar;

/**
 * The operator dashboard is composed through the `admin.dashboard` UI slot
 * (ADR-0003 enforcement point 3), like the per-event dashboard already is.
 * Platform's own contribution links the packages' dashboards, filtered by
 * the capability each one needs.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function platformDashboardOperator(array $permissions, string $host = 'admin.dashslot.test'): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach (['platform.access-admin', ...$permissions] as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Operator Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo(['platform.access-admin', ...$permissions]);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('registers the dashboard slot', function (): void {
    expect(app(SlotRegistry::class)->names())->toContain('admin.dashboard');
});

it('links Pulse, Horizon and Health for a user who may view diagnostics', function (): void {
    [, $admin] = platformDashboardOperator(['platform.view-diagnostics']);

    $this->actingAs($admin)
        ->get('http://admin.dashslot.test/admin')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $card = collect($page->toArray()['props']['slots'])->firstWhere('component', 'platform/DiagnosticsCard');
            $urls = collect($card['props']['links'])->pluck('url')->all();

            expect($urls)->toContain('/admin/pulse', '/admin/horizon')
                ->and($urls)->toContain(route('admin.health', absolute: false))
                ->and($urls)->not->toContain('/admin/telescope');
        });
});

it('offers no diagnostics link to a user without the capability', function (): void {
    [, $admin] = platformDashboardOperator([]);

    $this->actingAs($admin)
        ->get('http://admin.dashslot.test/admin')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $card = collect($page->toArray()['props']['slots'])->firstWhere('component', 'platform/DiagnosticsCard');

            expect($card['props']['links'])->toBe([]);
        });
});

it('links Telescope only to a user who may read the request log', function (): void {
    [, $admin] = platformDashboardOperator(['platform.view-request-log']);

    $this->actingAs($admin)
        ->get('http://admin.dashslot.test/admin')
        ->assertInertia(function (AssertableInertia $page): void {
            $card = collect($page->toArray()['props']['slots'])->firstWhere('component', 'platform/DiagnosticsCard');
            $urls = collect($card['props']['links'])->pluck('url')->all();

            $expected = class_exists(Telescope::class) && ! app()->isProduction();

            expect(in_array('/admin/telescope', $urls, true))->toBe($expected)
                ->and($urls)->not->toContain('/admin/pulse');
        });
});
