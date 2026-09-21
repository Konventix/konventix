<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Admission\Models\Admission;
use Konventix\Registration\Actions\CancelRegistration;
use Konventix\Registration\Actions\SubmitRegistration;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\SeatingMode;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;
use Konventix\Resources\Models\ResourceClaim;

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
