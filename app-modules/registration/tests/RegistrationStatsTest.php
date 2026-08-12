<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: `RegistrationStatsController` is the operator console's read
 * model for the `registration/EventStatsCard` slot contribution. The admin
 * host resolves no brand, so `BrandScope` hardens brand-owned queries to
 * nothing unless the controller explicitly opts back in — see the
 * controller's docblock for why every query here uses withoutBrandScope().
 */
function registrationStatsOperator(array $permissions = ['platform.access-admin', 'registration.view-registrations']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.regstats.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Operator Admin',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

it('404s on a brand host', function (): void {
    registrationStatsOperator();

    $this->get('http://localhost/admin/events/'.((string) Str::ulid()).'/registration-stats')
        ->assertNotFound();
});

it('403s an authenticated admin without the view-registrations capability', function (): void {
    [, $admin] = registrationStatsOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.regstats.test/admin/events/'.((string) Str::ulid()).'/registration-stats')
        ->assertForbidden();
});

it('returns status counts and per-type sold/quota data scoped to the event', function (): void {
    [, $admin] = registrationStatsOperator();

    $this->withBrand();
    $eventId = (string) Str::ulid();
    $otherEventId = (string) Str::ulid();

    $quota = Quota::factory()->withCapacity(10)->consumed(3)->create();

    $type = RegistrationType::factory()->forEvent($eventId)->create(['name' => 'Standard']);
    $type->quotas()->attach($quota);

    Registration::factory()->forType($type)->count(2)->create(['status' => RegistrationStatus::Confirmed]);
    Registration::factory()->forType($type)->create(['status' => RegistrationStatus::Submitted]);
    Registration::factory()->forType($type)->create(['status' => RegistrationStatus::Cancelled]);

    // A registration type belonging to a different event must not leak in.
    $otherType = RegistrationType::factory()->forEvent($otherEventId)->create();
    Registration::factory()->forType($otherType)->create(['status' => RegistrationStatus::Confirmed]);

    $response = $this->actingAs($admin)
        ->get("http://admin.regstats.test/admin/events/{$eventId}/registration-stats")
        ->assertOk();

    $response->assertJson([
        'counts' => [
            'submitted' => 1,
            'approved' => 0,
            'rejected' => 0,
            'confirmed' => 2,
            'cancelled' => 1,
        ],
    ]);

    // "Sold" = submitted + confirmed (still holding a quota slot); the
    // cancelled registration released its slot back (ReleasesQuotas).
    $response->assertJsonFragment([
        'id' => $type->id,
        'name' => 'Standard',
        'sold' => 3,
        'capacity' => 10,
        'remaining' => 7,
    ]);
});

it('reports null capacity/remaining for a type with no quota attached', function (): void {
    [, $admin] = registrationStatsOperator();

    $this->withBrand();
    $eventId = (string) Str::ulid();

    $type = RegistrationType::factory()->forEvent($eventId)->create(['name' => 'Unlimited']);
    Registration::factory()->forType($type)->create(['status' => RegistrationStatus::Confirmed]);

    $this->actingAs($admin)
        ->get("http://admin.regstats.test/admin/events/{$eventId}/registration-stats")
        ->assertOk()
        ->assertJsonFragment([
            'id' => $type->id,
            'name' => 'Unlimited',
            'sold' => 1,
            'capacity' => null,
            'remaining' => null,
        ]);
});
