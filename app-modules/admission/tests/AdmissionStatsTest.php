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
function admissionStatsOperator(
    array $permissions = ['platform.access-admin', 'admission.view-admissions'],
    ?Operator $operator = null,
    string $host = 'admin.admstats.test',
): array {
    $operator ??= Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

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
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $otherEventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    // The console account is the brand's own operator's (#329 final review
    // I2): the event must be this admin host's to be counted at all.
    [, $admin] = admissionStatsOperator(operator: Operator::query()->findOrFail($brand->operator_id));

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

it('404s another operator\'s event and counts nothing of it', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    Admission::factory()->count(2)->create(['event_id' => $eventId, 'state' => AdmissionState::Issued]);

    // A console account of a second operator, holding view-admissions on its
    // own team, that knows the first operator's event ULID (#329 final
    // review I2). The capability passes; the operator scope answers 404.
    [, $stranger] = admissionStatsOperator(host: 'admin.admstats-elsewhere.test');

    $this->actingAs($stranger)
        ->get("http://admin.admstats-elsewhere.test/admin/events/{$eventId}/admission-stats")
        ->assertNotFound();
});

it('404s an event Admission holds no summary of', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    Admission::factory()->create(['event_id' => $eventId, 'state' => AdmissionState::Issued]);
    [, $admin] = admissionStatsOperator(operator: Operator::query()->findOrFail($brand->operator_id));

    // Derivation: without `admission_event_summaries` there is no brand to
    // hold to the operator, so `findEventSummary()` answers 404 rather than
    // counting rows it cannot scope.
    $this->actingAs($admin)
        ->get("http://admin.admstats.test/admin/events/{$eventId}/admission-stats")
        ->assertNotFound();
});
