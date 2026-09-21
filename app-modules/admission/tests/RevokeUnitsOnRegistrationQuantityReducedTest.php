<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Admission\Exceptions\NotEnoughFreeAdmissionsException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\AdmissionClaimLink;
use Konventix\Admission\Models\AdmissionSeat;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Registration\Events\RegistrationQuantityReduced;

/**
 * D-REG-15: the contractual act did not end, it got smaller, so exactly N of
 * its access rights end — and only ones nobody is using.
 *
 * The listener is registered at boot
 * (`AdmissionServiceProvider::registerListeners()`), so these tests dispatch
 * the real event and exercise the real wiring — including the refusal, which
 * is what stops Commerce's queued refund from ever being pushed.
 *
 * Helper names prefixed `reduceUnits*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function reduceUnitsEvent(
    string $registrationId,
    string $reductionId,
    int $units,
    ?string $reducedByUserId = null,
): RegistrationQuantityReduced {
    return new RegistrationQuantityReduced(
        registrationId: $registrationId,
        reductionId: $reductionId,
        registrationTypeId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: (string) Str::ulid(),
        registrantUserId: (string) Str::ulid(),
        removedUnits: $units,
        previousQuantity: $units + 1,
        newQuantity: 1,
        // Already multiplied by the removed units, exactly as
        // `RegistrationConfirmed.grants` is (D-REG-15).
        removedGrants: [[
            'kind' => 'admission',
            'role' => 'visitor',
            'quantity' => $units,
            'resource_type_id' => null,
            'resource_type_ids' => null,
            'seating_mode' => null,
        ]],
        reasonCode: 'registrant-request',
        reducedByUserId: $reducedByUserId,
        reducedAt: now()->toIso8601String(),
    );
}

it('takes the plainest admissions first and leaves holders, seats and attendees alone', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();

    // Created in this order so "plain first" cannot be an accident of id
    // ordering: the linked one is older than the two plain ones.
    $linked = Admission::factory()->forRegistration($registrationId)->create();
    AdmissionClaimLink::factory()->create(['admission_id' => $linked->id]);

    $held = Admission::factory()->forRegistration($registrationId)->assigned()->create();

    $seated = Admission::factory()->forRegistration($registrationId)->create();
    // No factory ships with `AdmissionSeat` — it exists only through its own
    // projector — so the row is built directly, as the other read-model
    // tests in this module do.
    (new AdmissionSeat([
        'admission_id' => $seated->id,
        'booking_id' => (string) Str::ulid(),
        'place_label' => 'Row 4, seat 12',
    ]))->save();

    $attended = Admission::factory()->forRegistration($registrationId)->create();
    CheckIn::factory()->create([
        'admission_id' => $attended->id,
        'registration_id' => $registrationId,
    ]);

    $plainA = Admission::factory()->forRegistration($registrationId)->create();
    $plainB = Admission::factory()->forRegistration($registrationId)->create();

    Event::fake([AdmissionRevoked::class]);

    event(reduceUnitsEvent($registrationId, (string) Str::ulid(), 2));

    expect($plainA->refresh()->state)->toBe(AdmissionState::Revoked)
        ->and($plainB->refresh()->state)->toBe(AdmissionState::Revoked)
        // The invitation is still out, so it is the *next* candidate, not
        // one of the first two.
        ->and($linked->refresh()->state)->toBe(AdmissionState::Issued)
        ->and($held->refresh()->state)->toBe(AdmissionState::Assigned)
        ->and($seated->refresh()->state)->toBe(AdmissionState::Issued)
        ->and($attended->refresh()->state)->toBe(AdmissionState::Issued);

    Event::assertDispatchedTimes(AdmissionRevoked::class, 2);
    Event::assertDispatched(
        AdmissionRevoked::class,
        fn (AdmissionRevoked $e): bool => $e->admissionId === $plainA->id
            && $e->reasonCode === 'registration_quantity_reduced',
    );

    // ADR-0033: every door has to be told each "no" separately.
    expect(EntranceDeltaEntry::withoutBrandScope()
        ->where('kind', EntranceDeltaEntry::KIND_REVOKED)
        ->count())->toBe(2);
});

it('revokes the invitation with the admission once the plain ones run out', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();

    $plain = Admission::factory()->forRegistration($registrationId)->create();
    $linked = Admission::factory()->forRegistration($registrationId)->create();
    $link = AdmissionClaimLink::factory()->create(['admission_id' => $linked->id]);

    event(reduceUnitsEvent($registrationId, (string) Str::ulid(), 2));

    expect($plain->refresh()->state)->toBe(AdmissionState::Revoked)
        ->and($linked->refresh()->state)->toBe(AdmissionState::Revoked)
        // The invitee's URL stops working rather than dead-ending on a
        // revoked ticket (#292 §4.1).
        ->and($link->refresh()->revoked_at)->not->toBeNull();
});

it('refuses when fewer admissions are free than the reduction asks for', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();

    $free = Admission::factory()->forRegistration($registrationId)->create();
    $held = Admission::factory()->forRegistration($registrationId)->assigned()->create();

    expect(fn () => event(reduceUnitsEvent($registrationId, (string) Str::ulid(), 2)))
        ->toThrow(NotEnoughFreeAdmissionsException::class);

    // Nothing partial: the refusal happens before the first write.
    expect($free->refresh()->state)->toBe(AdmissionState::Issued)
        ->and($held->refresh()->state)->toBe(AdmissionState::Assigned)
        ->and(EntranceDeltaEntry::withoutBrandScope()->count())->toBe(0);
});

it('does nothing the second time the same reduction arrives', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $reductionId = (string) Str::ulid();

    $first = Admission::factory()->forRegistration($registrationId)->create();
    $second = Admission::factory()->forRegistration($registrationId)->create();

    event(reduceUnitsEvent($registrationId, $reductionId, 1));

    $revokedId = Admission::withoutBrandScope()
        ->where('registration_id', $registrationId)
        ->where('state', AdmissionState::Revoked)
        ->sole()
        ->id;

    // Replay: without the `reduction_id` key this would take the *next*
    // free admission as well.
    event(reduceUnitsEvent($registrationId, $reductionId, 1));

    expect(Admission::withoutBrandScope()
        ->where('registration_id', $registrationId)
        ->where('state', AdmissionState::Revoked)
        ->pluck('id')
        ->all())->toBe([$revokedId])
        ->and(EntranceDeltaEntry::withoutBrandScope()->count())->toBe(1)
        ->and([$first->id, $second->id])->toContain($revokedId);
});

it('ignores a reduction whose grants contain no admissions', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $admission = Admission::factory()->forRegistration($registrationId)->create();

    $event = reduceUnitsEvent($registrationId, (string) Str::ulid(), 1);

    event(new RegistrationQuantityReduced(
        registrationId: $event->registrationId,
        reductionId: $event->reductionId,
        registrationTypeId: $event->registrationTypeId,
        eventId: $event->eventId,
        brandId: $event->brandId,
        registrantUserId: $event->registrantUserId,
        removedUnits: 1,
        previousQuantity: 2,
        newQuantity: 1,
        // A pure seating package: nothing here for Admission to give back.
        removedGrants: [[
            'kind' => 'seating',
            'role' => 'seat',
            'quantity' => 1,
            'resource_type_id' => null,
            'resource_type_ids' => [(string) Str::ulid()],
            'seating_mode' => 'pick',
        ]],
        reasonCode: 'registrant-request',
        reducedByUserId: null,
        reducedAt: now()->toIso8601String(),
    ));

    expect($admission->refresh()->state)->toBe(AdmissionState::Issued);
});
