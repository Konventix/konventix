<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\EvaluateAdmissionValidity;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Registration\Events\RegistrationCancelled;

/**
 * ADR-0057 point 6: Admission never learns of money. There is no listener on
 * `PaymentReversed` — that would be the door asking "is it paid?" through the
 * back door, which ADR-0005 forbids — and between reversal and deadline the
 * admission stays valid. What Admission does consume is
 * `RegistrationCancelled`, and it revokes the admissions that registration
 * produced.
 *
 * A person already checked in keeps theirs: attendance happened, and the
 * unpaid reversal is Commerce's receivable (ADR-0057 point 4), not a reason
 * to retroactively deny a visit.
 *
 * The listener is registered at boot
 * (`AdmissionServiceProvider::registerListeners()`), so these tests dispatch
 * the real event and exercise the real wiring.
 *
 * Helper names prefixed `revoke*` — Pest helpers are process-global
 * (.ai/rules/tests.md).
 */
function revokeCancelledEvent(string $registrationId, ?string $cancelledByUserId = null): RegistrationCancelled
{
    return new RegistrationCancelled(
        registrationId: $registrationId,
        cancelledByUserId: $cancelledByUserId,
        reasonCode: 'payment_reversed',
        cancelledAt: now()->toIso8601String(),
    );
}

it('revokes every issued and assigned admission the cancelled registration produced', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();

    $issued = Admission::factory()->forRegistration($registrationId)->create();
    $assigned = Admission::factory()->forRegistration($registrationId)->assigned()->create();
    $someoneElse = Admission::factory()->create();

    Event::fake([AdmissionRevoked::class]);

    event(revokeCancelledEvent($registrationId));

    expect($issued->refresh()->state)->toBe(AdmissionState::Revoked)
        ->and($assigned->refresh()->state)->toBe(AdmissionState::Revoked)
        // Another registration's admission is untouched.
        ->and($someoneElse->refresh()->state)->toBe(AdmissionState::Issued);

    // The door's single authority now answers no for both (ADR-0005).
    $evaluate = app(EvaluateAdmissionValidity::class);
    expect($evaluate->handle($issued))->toBeFalse()
        ->and($evaluate->handle($assigned))->toBeFalse()
        ->and($evaluate->handle($someoneElse))->toBeTrue();

    Event::assertDispatchedTimes(AdmissionRevoked::class, 2);
    Event::assertDispatched(
        AdmissionRevoked::class,
        fn (AdmissionRevoked $event): bool => $event->admissionId === $issued->id
            && $event->registrationId === $registrationId
            && $event->eventId === $issued->event_id
            && $event->brandId === $issued->brand_id
            && $event->revokedByUserId === null
            && $event->reasonCode === 'registration_cancelled',
    );
});

it('carries the actor through when a person cancelled the registration', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $actorId = (string) Str::ulid();

    Admission::factory()->forRegistration($registrationId)->create();

    Event::fake([AdmissionRevoked::class]);

    event(revokeCancelledEvent($registrationId, cancelledByUserId: $actorId));

    Event::assertDispatched(
        AdmissionRevoked::class,
        fn (AdmissionRevoked $event): bool => $event->revokedByUserId === $actorId,
    );
});

it('keeps an admission that has already been checked in', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();

    $attended = Admission::factory()->forRegistration($registrationId)->create();
    $noShow = Admission::factory()->forRegistration($registrationId)->create();

    CheckIn::factory()->create([
        'admission_id' => $attended->id,
        'registration_id' => $registrationId,
    ]);

    Event::fake([AdmissionRevoked::class]);

    event(revokeCancelledEvent($registrationId));

    // Attendance happened; the money is Commerce's receivable, not the
    // door's problem (ADR-0057 points 4 and 6).
    expect($attended->refresh()->state)->toBe(AdmissionState::Issued)
        ->and($noShow->refresh()->state)->toBe(AdmissionState::Revoked);

    Event::assertDispatchedTimes(AdmissionRevoked::class, 1);
});

it('revokes an admission whose only scan was rejected', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $admission = Admission::factory()->forRegistration($registrationId)->create();

    // A rejected scan is an audit row, not an attendance: the person never
    // got in, so nothing was consumed.
    CheckIn::factory()->rejected('expired')->create([
        'admission_id' => $admission->id,
        'registration_id' => $registrationId,
    ]);

    event(revokeCancelledEvent($registrationId));

    expect($admission->refresh()->state)->toBe(AdmissionState::Revoked);
});

it('does nothing for a registration that produced no admissions', function (): void {
    $this->withBrand();

    Event::fake([AdmissionRevoked::class]);

    event(revokeCancelledEvent((string) Str::ulid()));

    Event::assertNotDispatched(AdmissionRevoked::class);
});

it('is idempotent: replaying the cancellation revokes nothing a second time', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    Admission::factory()->forRegistration($registrationId)->create();

    Event::fake([AdmissionRevoked::class]);

    $cancelled = revokeCancelledEvent($registrationId);

    event($cancelled);
    event($cancelled);

    Event::assertDispatchedTimes(AdmissionRevoked::class, 1);
});

it('tells the offline doors, one delta entry per admission it actually revoked', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();

    $attended = Admission::factory()->forRegistration($registrationId)->create();
    $revoked = Admission::factory()->forRegistration($registrationId)->create();

    CheckIn::factory()->create([
        'admission_id' => $attended->id,
        'registration_id' => $registrationId,
    ]);

    event(revokeCancelledEvent($registrationId));

    // ADR-0033: the signature can say yes on its own, so a device only ever
    // learns "no" from the delta feed. Without a row here an offline door
    // keeps admitting this person until its next full sync.
    $entries = EntranceDeltaEntry::withoutBrandScope()
        ->where('kind', EntranceDeltaEntry::KIND_REVOKED)
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->admission_id)->toBe($revoked->id)
        ->and($entries->first()->event_id)->toBe($revoked->event_id)
        ->and($entries->first()->brand_id)->toBe($revoked->brand_id);
});

it('writes no revocation delta for an admission it kept', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $attended = Admission::factory()->forRegistration($registrationId)->create();

    CheckIn::factory()->create([
        'admission_id' => $attended->id,
        'registration_id' => $registrationId,
    ]);

    event(revokeCancelledEvent($registrationId));

    expect(EntranceDeltaEntry::withoutBrandScope()->where('admission_id', $attended->id)->exists())->toBeFalse();
});
