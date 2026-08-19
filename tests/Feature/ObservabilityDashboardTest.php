<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Konventix\Identity\Models\User;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pulse, Horizon and Telescope, held to the same two rules as the rest of the
 * console: they exist only on the operator's host (ADR-0029), and access is a
 * capability rather than an environment (ADR-0013).
 *
 * Both rules replace a package default that fails in two directions at once.
 * Each of these three authorizes with `app()->environment('local')` out of the
 * box, which means nobody can open them in production however roles are
 * configured, and anybody who can reach the host can open them in development
 * without logging in. The tests below would pass on those defaults only by
 * accident, so each states which capability it is exercising.
 *
 * @return array{0: User, 1: Brand, 2: Operator}
 */
function observabilityOperator(array $capabilities): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.observability.test'])->save();

    $brand = Brand::factory()->withDomain('brand.observability.test')->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($capabilities as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Observability Tester',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($capabilities);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$admin, $brand, $operator];
}

/**
 * Capabilities are scoped to a permission team (ADR-0013), and an HTTP request
 * gets that team set for it by ResolveBrand. A gate checked outside a request
 * has no team, so `can()` answers false for everything — this establishes the
 * context the middleware would have established.
 */
function observabilityActingAsOperator(Operator $operator): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($operator->getKey());
}

/**
 * The suite runs with APP_ENV=testing, so every one of these passing proves the
 * capability was consulted — the packages' own local-only defaults would refuse
 * all of them.
 */
it('opens pulse for an operator holding the diagnostics capability', function (): void {
    [$admin] = observabilityOperator(['platform.access-admin', 'platform.view-diagnostics']);

    $this->actingAs($admin)
        ->get('http://admin.observability.test/admin/pulse')
        ->assertOk();
});

it('opens horizon for an operator holding the diagnostics capability', function (): void {
    [$admin] = observabilityOperator(['platform.access-admin', 'platform.view-diagnostics']);

    $this->actingAs($admin)
        ->get('http://admin.observability.test/admin/horizon')
        ->assertOk();
});

it('refuses pulse and horizon to console access alone', function (): void {
    [$admin] = observabilityOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.observability.test/admin/pulse')
        ->assertForbidden();

    $this->actingAs($admin)
        ->get('http://admin.observability.test/admin/horizon')
        ->assertForbidden();
});

/**
 * Telescope is asserted at the gate rather than over HTTP, and deliberately so:
 * `TELESCOPE_ENABLED=false` in phpunit.xml keeps it from registering routes or
 * recording during the suite, which is right — a thousand tests' worth of
 * captured requests is cost with no reader. The middleware position it shares
 * with Pulse and Horizon is already proved over HTTP above.
 *
 * The distinction being tested is the reason Telescope has a capability of its
 * own: it records request bodies, so opening it reads other people's personal
 * data rather than metrics, and a role trusted to watch the queues is not
 * automatically trusted with that.
 */
it('separates seeing metrics from reading request bodies', function (): void {
    [$diagnosticsOnly, , $operator] = observabilityOperator(['platform.access-admin', 'platform.view-diagnostics']);
    observabilityActingAsOperator($operator);

    expect(Gate::forUser($diagnosticsOnly)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($diagnosticsOnly)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($diagnosticsOnly)->allows('viewTelescope'))->toBeFalse();
});

it('opens telescope only for the request-log capability', function (): void {
    [$reader, , $operator] = observabilityOperator(['platform.access-admin', 'platform.view-request-log']);
    observabilityActingAsOperator($operator);

    // Passing here also proves the package's own default was replaced: its
    // `viewTelescope` is `app()->environment('local')`, and this suite is not.
    expect(Gate::forUser($reader)->allows('viewTelescope'))->toBeTrue()
        ->and(Gate::forUser($reader)->allows('viewPulse'))->toBeFalse();
});

it('keeps telescope on the operator host too', function (): void {
    // Config rather than a request, for the same reason as above. The
    // middleware itself is proved over HTTP by the Pulse and Horizon cases.
    expect(config('telescope.middleware'))
        ->toContain(EnsureOperatorHost::class)
        ->and(config('telescope.path'))->toStartWith('admin/');
});

/**
 * ADR-0029, applied to the dashboards. `web` alone would serve these happily on
 * a brand host, because ResolveBrand resolves that host perfectly well — a
 * diagnostics console reachable at a public event's own domain. 404 rather
 * than 403: their existence is nobody's business there.
 */
it('does not exist on a brand host, even for somebody who may see it', function (): void {
    [$admin] = observabilityOperator([
        'platform.access-admin',
        'platform.view-diagnostics',
        'platform.view-request-log',
    ]);

    foreach (['pulse', 'horizon'] as $dashboard) {
        $this->actingAs($admin)
            ->get("http://brand.observability.test/admin/{$dashboard}")
            ->assertNotFound();
    }
});

it('does not exist on a host belonging to nobody', function (): void {
    [$admin] = observabilityOperator(['platform.access-admin', 'platform.view-diagnostics']);

    $this->actingAs($admin)
        ->get('http://stranger.observability.test/admin/pulse')
        ->assertNotFound();
});
