<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Registration\Actions\AmendRegistration;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\JoinWaitlist;
use Konventix\Registration\Actions\ReduceRegistrationQuantity;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Enums\SeatingMode;
use Konventix\Registration\Events\RegistrationQuantityReduced;
use Konventix\Registration\Events\WaitlistOfferMade;
use Konventix\Registration\Exceptions\QuantityReductionRefusedException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Models\ResourceClaim;
use Spatie\Permission\PermissionRegistrar;

/**
 * What the buyer's quantity multiplies, end to end (bugfix round
 * 2026-09-21, Task 19).
 *
 * The multiplication happens exactly once, in `ConfirmRegistration`'s
 * `RegistrationConfirmed` payload, which is why nothing in Admission,
 * Resources or Community changed for this: they read the final count and
 * never learn that a registration has a quantity at all. That makes this
 * file the place where the claim is pinned — from the form's number to the
 * admissions issued, the seats claimed and the units given back.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (.ai/rules/tests.md).
 */
function quantityTestOperator(array $permissions): array
{
    $operator = Operator::factory()->create();
    // `operators.admin_host` is unique across the suite — this file needs its
    // own (.ai/rules/tests.md).
    $operator->forceFill(['admin_host' => 'admin.quantity.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create(['name' => 'Operator Admin', 'guard_name' => 'web', 'brand_id' => $operator->getKey()]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

function quantityTestType(array $grants, int $cap = 10): RegistrationType
{
    return RegistrationType::factory()
        ->withGrants($grants)
        ->maxQuantityPerRegistration($cap)
        ->create();
}

it('issues grant quantity times registration quantity admissions', function (): void {
    $this->withBrand();

    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 2),
    ]);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    // Three double tickets are six people at the door.
    expect(Admission::where('registration_id', $registration->id)->count())->toBe(6);
});

it('scales a seat claim with the registration quantity', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('resources', $brand);

    $categoryId = (string) Str::ulid();
    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
        new GrantData(
            kind: GrantKind::Seating,
            role: 'seat',
            quantity: 2,
            resourceTypeIds: [$categoryId],
            seatingMode: SeatingMode::Pick,
        ),
    ]);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    $claim = ResourceClaim::withoutBrandScope()
        ->where('registration_id', $registration->id)
        ->sole();

    // ADR-0060 point 5: N places for quantity N are one set — one claim,
    // six places.
    expect($claim->quantity)->toBe(6);
});

it('releases every unit a multi-unit registration held, and only those', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(10)->create();
    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);
    $type->quotas()->attach($quota);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(4),
    );

    expect($quota->fresh()->consumed)->toBe(4);

    (new CancelRegistration)->handle($registration, (string) Str::ulid(), 'registrant-withdrew');

    // Consume and release are symmetric or capacity leaks: releasing one
    // unit here would keep three seats sold forever.
    expect($quota->fresh()->consumed)->toBe(0);
});

it('leaves a single-unit registration behaving exactly as before', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(10)->create();
    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 2),
    ]);
    $type->quotas()->attach($quota);

    $registration = app(SubmitRegistration::class)->handle($type, (string) Str::ulid(), emptySubmission());

    expect($registration->quantity)->toBe(1)
        ->and($quota->fresh()->consumed)->toBe(1)
        ->and(Admission::where('registration_id', $registration->id)->count())->toBe(2);

    (new CancelRegistration)->handle($registration, (string) Str::ulid(), 'registrant-withdrew');

    expect($quota->fresh()->consumed)->toBe(0);
});

/**
 * Partial cancellation (Task 23, D-REG-15) — the subtractive half of the
 * quantity story above, so it lives in the same file.
 */
it('releases exactly the reduced units and offers them one at a time to the waitlist', function (): void {
    $brand = $this->withBrand();

    $quota = Quota::factory()->withCapacity(3)->create();
    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);
    $type->quotas()->attach($quota);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    expect($quota->fresh()->consumed)->toBe(3);

    // Two people waiting, so the two freed units have somewhere to go.
    foreach (['first', 'second'] as $who) {
        app(JoinWaitlist::class)->handle($type, (string) Str::ulid(), emptySubmission());
    }

    Event::fake([WaitlistOfferMade::class]);

    $reduction = app(ReduceRegistrationQuantity::class)->handle(
        registration: $registration,
        units: 2,
        reasonCode: 'registrant-request',
        reducedByUserId: (string) Str::ulid(),
    );

    // The waitlist stays one unit per entry (D-REG-12), so two released
    // units are two offers — not one offer for two seats.
    Event::assertDispatchedTimes(WaitlistOfferMade::class, 2);

    expect($registration->refresh()->quantity)->toBe(1)
        ->and($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($reduction->units)->toBe(2)
        ->and($reduction->brand_id)->toBe($brand->getKey())
        // Four of the six admissions are gone, two remain for the unit kept.
        ->and(Admission::where('registration_id', $registration->id)
            ->where('state', AdmissionState::Revoked)->count())->toBe(2)
        ->and(Admission::where('registration_id', $registration->id)
            ->where('state', AdmissionState::Issued)->count())->toBe(1);

    // The two offers took the freed units straight back, so the quota is
    // full again — released and re-consumed, not leaked.
    expect($quota->fresh()->consumed)->toBe(3);
});

it('refuses a reduction of zero, of everything, and of an unconfirmed registration', function (): void {
    $this->withBrand();

    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    $reduce = app(ReduceRegistrationQuantity::class);

    expect(fn () => $reduce->handle($registration, 0, 'operator-correction'))
        ->toThrow(QuantityReductionRefusedException::class);

    // Removing the last unit is a cancellation, not a reduction.
    expect(fn () => $reduce->handle($registration, 3, 'operator-correction'))
        ->toThrow(QuantityReductionRefusedException::class);

    expect($registration->refresh()->quantity)->toBe(3);

    $pending = Registration::factory()->forType($type)->create(['quantity' => 3]);

    expect(fn () => $reduce->handle($pending, 1, 'operator-correction'))
        ->toThrow(QuantityReductionRefusedException::class);
});

it('leaves an amendment\'s own units standing, so a later cancellation releases each unit once', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(10)->create();
    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);
    $type->quotas()->attach($quota);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    app(AmendRegistration::class)->handle($registration, [
        new GrantData(kind: GrantKind::Admission, role: 'exhibitor_crew', quantity: 2),
    ]);

    // Three units plus two amended admissions.
    expect($quota->fresh()->consumed)->toBe(5);

    app(ReduceRegistrationQuantity::class)->handle($registration, 2, 'registrant-request');

    // Only the two reduced units came back; `amendedUnits()` is untouched.
    expect($quota->fresh()->consumed)->toBe(3);

    (new CancelRegistration)->handle($registration->refresh(), null, 'operator');

    // One remaining unit plus the two amended ones — released exactly once.
    expect($quota->fresh()->consumed)->toBe(0);
});

it('does nothing twice when the reduction event is replayed', function (): void {
    $this->withBrand();

    $quota = Quota::factory()->withCapacity(10)->create();
    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);
    $type->quotas()->attach($quota);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    $reduction = app(ReduceRegistrationQuantity::class)->handle($registration, 1, 'registrant-request');

    $revokedIds = Admission::where('registration_id', $registration->id)
        ->where('state', AdmissionState::Revoked)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    // The same announcement again — a queue redelivery, a manual replay.
    event(new RegistrationQuantityReduced(
        registrationId: $registration->id,
        reductionId: $reduction->id,
        registrationTypeId: $type->id,
        eventId: $registration->event_id,
        brandId: $registration->brand_id,
        registrantUserId: $registration->registrant_user_id,
        removedUnits: 1,
        previousQuantity: 3,
        newQuantity: 2,
        removedGrants: [[
            'kind' => 'admission',
            'role' => 'visitor',
            'quantity' => 1,
            'resource_type_id' => null,
            'resource_type_ids' => null,
            'seating_mode' => null,
        ]],
        reasonCode: 'registrant-request',
        reducedByUserId: null,
        reducedAt: now()->toIso8601String(),
    ));

    $stillRevoked = Admission::where('registration_id', $registration->id)
        ->where('state', AdmissionState::Revoked)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($stillRevoked)->toBe($revokedIds);
});

it('refuses the reduce route without the capability', function (): void {
    [, $reader] = quantityTestOperator(['platform.access-admin', 'registration.view-registrations']);

    $this->withBrand();

    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);

    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    $this->actingAs($reader)
        ->post('http://admin.quantity.test/admin/registrations/'.$registration->id.'/reduce-quantity', [
            'units' => 1,
            'reasonCode' => 'registrant-request',
        ])
        ->assertForbidden();

    expect($registration->refresh()->quantity)->toBe(3);
});

it('reduces through the console and records the audit entry', function (): void {
    [, $admin] = quantityTestOperator([
        'platform.access-admin',
        'registration.view-registrations',
        'registration.reduce-registration-quantity',
    ]);

    $this->withBrand();

    $type = quantityTestType([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
    ]);

    // Created before the admin-host request: that request resolves no brand
    // and BrandScope hardens every brand-owned query (.ai/rules/tests.md).
    $registration = app(SubmitRegistration::class)->handle(
        $type,
        (string) Str::ulid(),
        quantitySubmission(3),
    );

    $this->actingAs($admin)
        ->post('http://admin.quantity.test/admin/registrations/'.$registration->id.'/reduce-quantity', [
            'units' => 2,
            'reasonCode' => 'registrant-request',
            'reasonNote' => 'Two colleagues dropped out.',
        ])
        ->assertRedirect();

    expect($registration->refresh()->quantity)->toBe(1);

    $entry = AuditLogEntry::query()->where('action', 'registration.quantity-reduced')->sole();

    expect($entry->context['units'])->toBe(2)
        ->and($entry->context['previous_quantity'])->toBe(3)
        ->and($entry->context['new_quantity'])->toBe(1);
});
