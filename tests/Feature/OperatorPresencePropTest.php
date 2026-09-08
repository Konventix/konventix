<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Konventix\Compliance\Database\Seeders\OperatorLegalTextsSeeder;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * #184 / ADR-0054: the account is the operator's (ADR-0016), so every auth
 * surface names the operator and links its other brands. Platform resolves
 * that where it is shown — the auth pages ask for it, and nothing else does
 * (fix round 1) — and it is null on the operator console's own host, which
 * resolves no brand (ADR-0029).
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

    // The console's own login page is an auth surface, so it carries the
    // prop — and it is null, because no brand answers on the admin host.
    $this->get('http://admin.acme.test/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('operatorPresence', null));

    $this->actingAs($admin)
        ->get('http://admin.acme.test/admin')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('operatorPresence'));
});

it('costs a brand page that never shows the notice nothing at all', function (): void {
    presenceOperatorWithBrands(['Alpha Con']);

    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    // A plain brand-host page. The prop is not there, and none of the three
    // queries behind it ran.
    $this->get('http://alpha-con.test/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('operatorPresence'));

    // The two `OperatorPresence` runs itself: the operator's brands, and
    // their primary domains. The brand-resolution and footer queries every
    // brand-host page makes are not this prop's.
    $operatorQueries = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'from "brands" where "operator_id"')
            || str_contains($sql, 'from "brand_domains" where "brand_id" in'),
    ));

    expect($operatorQueries)->toBe([]);
});

it('links the Art. 13 sentence only where a privacy policy resolves', function (): void {
    presenceOperatorWithBrands(['Alpha Con']);

    $this->get('http://alpha-con.test/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('operatorPresence.privacyPolicyUrl', null));

    app(OperatorLegalTextsSeeder::class)->run();

    $this->get('http://alpha-con.test/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('operatorPresence.privacyPolicyUrl', '/policies/privacy'));
});
