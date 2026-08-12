<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ADR-0029: `AdmissionStatsController` is the operator console's read model
 * for the `admission/EventStatsCard` slot contribution — see the
 * controller's docblock for why every query here uses withoutBrandScope()
 * deliberately.
 */
function admissionStatsOperator(array $permissions = ['platform.access-admin', 'admission.view-admissions']): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.admstats.test'])->save();

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
    admissionStatsOperator();

    $this->get('http://localhost/admin/events/'.((string) Str::ulid()).'/admission-stats')
        ->assertNotFound();
});

it('403s an authenticated admin without the view-admissions capability', function (): void {
    [, $admin] = admissionStatsOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.admstats.test/admin/events/'.((string) Str::ulid()).'/admission-stats')
        ->assertForbidden();
});

it('returns lifecycle-state counts scoped to the event', function (): void {
    [, $admin] = admissionStatsOperator();

    $this->withBrand();
    $eventId = (string) Str::ulid();
    $otherEventId = (string) Str::ulid();

    Admission::factory()->count(2)->create(['event_id' => $eventId, 'state' => AdmissionState::Issued]);
    Admission::factory()->create(['event_id' => $eventId, 'state' => AdmissionState::Assigned]);
    Admission::factory()->create(['event_id' => $eventId, 'state' => AdmissionState::Revoked]);

    // A different event's admission must not leak in.
    Admission::factory()->create(['event_id' => $otherEventId, 'state' => AdmissionState::Issued]);

    $this->actingAs($admin)
        ->get("http://admin.admstats.test/admin/events/{$eventId}/admission-stats")
        ->assertOk()
        ->assertJson([
            'counts' => [
                'issued' => 2,
                'assigned' => 1,
                'revoked' => 1,
                'expired' => 0,
            ],
            'total' => 4,
        ]);
});
