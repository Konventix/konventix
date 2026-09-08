<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * #184 / ADR-0054: the account is the operator's (ADR-0016), so every auth
 * surface names the operator and links its other brands. Platform shares
 * that beside the footer data — on brand hosts only, never on the operator
 * console's own host (ADR-0029).
 *
 * Helper names prefixed `presence*`: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function presenceOperatorWithBrands(array $names): array
{
    $operator = Operator::factory()->create([
        'name' => 'Acme Events',
        'legal_name' => 'Acme Events GmbH',
    ]);

    $brands = [];

    foreach ($names as $index => $name) {
        $brands[$name] = Brand::factory()
            ->withDomain(str($name)->slug()->value().'.test')
            ->create(['name' => $name, 'operator_id' => $operator->getKey()]);
    }

    return [$operator, $brands];
}

it('names the operator and lists its brands on a brand host', function (): void {
    presenceOperatorWithBrands(['Beta Con', 'Alpha Con', 'Gamma Con']);

    $this->get('http://alpha-con.test/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('operatorPresence.name', 'Acme Events')
            ->where('operatorPresence.legalName', 'Acme Events GmbH')
            ->has('operatorPresence.brands', 3)
            // Alphabetical, so the list reads the same on every brand.
            ->where('operatorPresence.brands.0.name', 'Alpha Con')
            ->where('operatorPresence.brands.0.current', true)
            ->where('operatorPresence.brands.0.url', 'http://alpha-con.test')
            ->where('operatorPresence.brands.1.name', 'Beta Con')
            ->where('operatorPresence.brands.1.current', false)
            ->where('operatorPresence.brands.1.url', 'http://beta-con.test'));
});

it('carries only the current brand when the operator has one', function (): void {
    presenceOperatorWithBrands(['Only Con']);

    $this->get('http://only-con.test/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('operatorPresence.name', 'Acme Events')
            ->has('operatorPresence.brands', 1)
            ->where('operatorPresence.brands.0.current', true));
});

it('shares nothing on the operator host, which resolves no brand', function (): void {
    [$operator] = presenceOperatorWithBrands(['Alpha Con']);
    $operator->forceFill(['admin_host' => 'admin.acme.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());
    PermissionModel::query()->firstOrCreate(['name' => 'platform.access-admin', 'guard_name' => 'web']);
    $role = Role::query()->create(['name' => 'Presence Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo(['platform.access-admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $registrar->setPermissionsTeamId(null);

    $this->actingAs($admin)
        ->get('http://admin.acme.test/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('operatorPresence', null));
});
