<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Models\Admission;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationConfirmed;

/**
 * TODO.md: "Listen to RegistrationConfirmed and issue one admission per
 * granted admission in the payload. The listener must be idempotent and
 * replayable." The listener is registered unconditionally at boot
 * (`AdmissionServiceProvider::registerListeners()`), so dispatching the real
 * event exercises the real wiring — this is a feature test, not a unit test
 * that calls the Action directly.
 */
function confirmedRegistrationEvent(array $grants, ?string $registrationId = null): RegistrationConfirmed
{
    return new RegistrationConfirmed(
        registrationId: $registrationId ?? (string) Str::ulid(),
        registrationTypeId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: app(BrandContext::class)->currentOrFail()->getKey(),
        registrantUserId: (string) Str::ulid(),
        confirmedAt: now()->toIso8601String(),
        grants: $grants,
    );
}

it('issues one admission per unit of quantity on an admission grant', function (): void {
    $this->withBrand();

    $event = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 3, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);

    event($event);

    $admissions = Admission::where('registration_id', $event->registrationId)->get();

    expect($admissions)->toHaveCount(3);
    expect($admissions->pluck('role')->unique()->all())->toBe(['visitor']);
    expect($admissions->pluck('state')->unique()->first())->toBe(AdmissionState::Issued);
    expect($admissions->pluck('holder_user_id')->filter()->isEmpty())->toBeTrue();
});

it('issues admissions across multiple grant entries and skips non-admission kinds', function (): void {
    $this->withBrand();

    $event = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 2, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
        ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => 1, 'resource_type_id' => (string) Str::ulid(), 'resource_type_ids' => null, 'seating_mode' => null],
        ['kind' => 'admission', 'role' => 'exhibitor_crew', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);

    event($event);

    $admissions = Admission::where('registration_id', $event->registrationId)->get();

    expect($admissions)->toHaveCount(3);
    expect($admissions->where('role', 'visitor'))->toHaveCount(2);
    expect($admissions->where('role', 'exhibitor_crew'))->toHaveCount(1);
});

it('is idempotent: replaying the same confirmation issues nothing new', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $event = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 2, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ], $registrationId);

    event($event);
    expect(Admission::where('registration_id', $registrationId)->count())->toBe(2);

    // Replay the exact same event — a queue redelivery, a retried listener.
    event($event);

    expect(Admission::where('registration_id', $registrationId)->count())->toBe(2);
});

it('emits AdmissionIssued with the SPEC scalar payload for every admission it creates', function (): void {
    $this->withBrand();

    Event::fake([AdmissionIssued::class]);

    $event = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);

    // Event::fake([AdmissionIssued::class]) only intercepts that one event
    // class — RegistrationConfirmed still dispatches for real, so the real
    // listener still runs and creates the Admission row.
    event($event);

    Event::assertDispatched(
        AdmissionIssued::class,
        fn (AdmissionIssued $issued): bool => $issued->registrationId === $event->registrationId
            && $issued->eventId === $event->eventId
            && $issued->brandId === $event->brandId
            && $issued->role === 'visitor'
            && $issued->holderUserId === null,
    );
});

/**
 * ADR-0036 delta issuance. Prefixed helper name: Pest helper functions are
 * process-global across every module's test files (.ai/rules/tests.md).
 */
function admissionTestAmendedEvent(array $grants, string $registrationId, string $amendmentId): RegistrationAmended
{
    return new RegistrationAmended(
        registrationId: $registrationId,
        amendmentId: $amendmentId,
        registrationTypeId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        brandId: app(BrandContext::class)->currentOrFail()->getKey(),
        registrantUserId: (string) Str::ulid(),
        grants: $grants,
        amendedAt: now()->toIso8601String(),
    );
}

it('issues exactly the delta for an amendment on a registration that already has admissions', function (): void {
    $this->withBrand();

    $confirmed = confirmedRegistrationEvent([
        ['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null],
    ]);
    event($confirmed);

    $amendmentId = (string) Str::ulid();

    // The old "does this registration have any admissions?" guard would have
    // swallowed this entirely.
    event(admissionTestAmendedEvent(
        [['kind' => 'admission', 'role' => 'exhibitor_crew', 'quantity' => 2, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null]],
        $confirmed->registrationId,
        $amendmentId,
    ));

    $admissions = Admission::where('registration_id', $confirmed->registrationId)->get();

    expect($admissions)->toHaveCount(3)
        ->and($admissions->whereNull('amendment_id'))->toHaveCount(1)
        ->and($admissions->where('amendment_id', $amendmentId))->toHaveCount(2)
        ->and($admissions->where('role', 'exhibitor_crew')->pluck('state')->unique()->first())
        ->toBe(AdmissionState::Issued);
});

it('is idempotent per amendment: replaying one amendment issues nothing new', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $amendmentId = (string) Str::ulid();
    $grants = [['kind' => 'admission', 'role' => 'exhibitor_crew', 'quantity' => 2, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null]];

    event(admissionTestAmendedEvent($grants, $registrationId, $amendmentId));
    event(admissionTestAmendedEvent($grants, $registrationId, $amendmentId));

    expect(Admission::where('registration_id', $registrationId)->count())->toBe(2);
});

it('treats two different amendments on one registration as separate deltas', function (): void {
    $this->withBrand();

    $registrationId = (string) Str::ulid();
    $grants = [['kind' => 'admission', 'role' => 'exhibitor_crew', 'quantity' => 1, 'resource_type_id' => null, 'resource_type_ids' => null, 'seating_mode' => null]];

    event(admissionTestAmendedEvent($grants, $registrationId, (string) Str::ulid()));
    event(admissionTestAmendedEvent($grants, $registrationId, (string) Str::ulid()));

    expect(Admission::where('registration_id', $registrationId)->count())->toBe(2);
});
