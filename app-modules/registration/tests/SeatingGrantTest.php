<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Registration\Actions\AmendRegistration;
use Konventix\Registration\Actions\ConfirmRegistration;
use Konventix\Registration\Actions\CreateRegistrationType;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\RegistrationTypeData;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\GrantKind;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Konventix\Registration\Enums\SeatingMode;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Models\Registration;
use Konventix\Registration\Models\RegistrationType;

/**
 * The seating grant (seating design §7.1): a third `GrantKind` that names the
 * categories (Resources' resource type ULIDs, no FK — ADR-0014) a confirmed
 * registration may pick from, how (`pick` / `assign`) and how many places.
 * The cross-grant rules live in the Actions, not the form, so a type built
 * from tinker is refused on the same grounds as one saved from the console.
 *
 * Helper names prefixed `seatingGrant`: Pest helpers are process-global
 * (.ai/rules/tests.md). They are used by this file only, so they stay here.
 */
function seatingGrantTypeData(array $grants, ?string $eventId = null): RegistrationTypeData
{
    return new RegistrationTypeData(
        eventId: $eventId ?? (string) Str::ulid(),
        name: 'Seated visitor',
        visibility: RegistrationVisibility::Public,
        approval: RegistrationApproval::None,
        settlement: RegistrationSettlement::Free,
        confirmationGate: ConfirmationGate::OnSubmit,
        termsDays: 0,
        grants: $grants,
    );
}

/**
 * @return array<string, array<int, string>> the validation errors the attempt raised
 */
function seatingGrantErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a ValidationException and got none.');
}

it('validates every resource type id of a seating grant as a ULID', function (): void {
    $errors = seatingGrantErrors(fn () => GrantData::validateAndCreate([
        'kind' => 'seating',
        'role' => 'seat',
        'quantity' => 2,
        'resourceTypeIds' => [(string) Str::ulid(), 'not-a-ulid'],
        'seatingMode' => 'pick',
    ]));

    expect($errors)->toHaveKey('resourceTypeIds.1');
});

it('accepts a well-formed seating grant and types its fields', function (): void {
    $categoryId = (string) Str::ulid();

    $grant = GrantData::validateAndCreate([
        'kind' => 'seating',
        'role' => 'seat',
        'quantity' => 2,
        'resourceTypeIds' => [$categoryId],
        'seatingMode' => 'assign',
    ]);

    expect($grant->kind)->toBe(GrantKind::Seating)
        ->and($grant->resourceTypeIds)->toBe([$categoryId])
        ->and($grant->seatingMode)->toBe(SeatingMode::Assign)
        ->and($grant->resourceTypeId)->toBeNull();
});

it('leaves admission and resource_claim grants untouched by the new fields', function (): void {
    $grant = GrantData::validateAndCreate(['kind' => 'admission', 'role' => 'visitor', 'quantity' => 1]);

    expect($grant->resourceTypeIds)->toBeNull()
        ->and($grant->seatingMode)->toBeNull();
});

it('refuses a seating grant without categories', function (): void {
    $brand = $this->withBrand();

    $errors = seatingGrantErrors(fn () => app(CreateRegistrationType::class)->handle($brand->id, seatingGrantTypeData([
        new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 1, resourceTypeIds: [], seatingMode: SeatingMode::Pick),
    ])));

    expect($errors)->toHaveKey('grants')
        ->and(RegistrationType::query()->count())->toBe(0);
});

it('refuses a seating grant without a mode', function (): void {
    $brand = $this->withBrand();

    $errors = seatingGrantErrors(fn () => app(CreateRegistrationType::class)->handle($brand->id, seatingGrantTypeData([
        new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 1, resourceTypeIds: [(string) Str::ulid()]),
    ])));

    expect($errors)->toHaveKey('grants')
        ->and(RegistrationType::query()->count())->toBe(0);
});

it('refuses a resource claim and a seating grant on the same category', function (): void {
    $brand = $this->withBrand();
    $categoryId = (string) Str::ulid();

    $errors = seatingGrantErrors(fn () => app(CreateRegistrationType::class)->handle($brand->id, seatingGrantTypeData([
        new GrantData(kind: GrantKind::ResourceClaim, role: 'booth', quantity: 1, resourceTypeId: $categoryId),
        new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 1, resourceTypeIds: [$categoryId], seatingMode: SeatingMode::Pick),
    ])));

    expect($errors)->toHaveKey('grants')
        ->and(RegistrationType::query()->count())->toBe(0);
});

it('refuses two seating grants on one type', function (): void {
    // resource_claims is unique per (registration_id, amendment_id): one claim
    // per confirmation occasion. Several categories go into one grant.
    $brand = $this->withBrand();

    $errors = seatingGrantErrors(fn () => app(CreateRegistrationType::class)->handle($brand->id, seatingGrantTypeData([
        new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 1, resourceTypeIds: [(string) Str::ulid()], seatingMode: SeatingMode::Pick),
        new GrantData(kind: GrantKind::Seating, role: 'vip', quantity: 1, resourceTypeIds: [(string) Str::ulid()], seatingMode: SeatingMode::Pick),
    ])));

    expect($errors)->toHaveKey('grants')
        ->and(RegistrationType::query()->count())->toBe(0);
});

it('stores a seating grant beside a resource claim on another category and round-trips it', function (): void {
    $brand = $this->withBrand();
    $seatCategory = (string) Str::ulid();
    $boothCategory = (string) Str::ulid();

    $type = app(CreateRegistrationType::class)->handle($brand->id, seatingGrantTypeData([
        new GrantData(kind: GrantKind::ResourceClaim, role: 'booth', quantity: 1, resourceTypeId: $boothCategory),
        new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 2, resourceTypeIds: [$seatCategory], seatingMode: SeatingMode::Pick),
    ]));

    $grants = $type->fresh()->grants->toCollection();

    expect($grants)->toHaveCount(2)
        ->and($grants->last()->kind)->toBe(GrantKind::Seating)
        ->and($grants->last()->resourceTypeIds)->toBe([$seatCategory])
        ->and($grants->last()->seatingMode)->toBe(SeatingMode::Pick)
        ->and($grants->last()->quantity)->toBe(2);
});

it('guards an amendment with the same seating rules', function (): void {
    $this->withBrand();

    $registration = Registration::factory()->confirmed()->create();

    $errors = seatingGrantErrors(fn () => app(AmendRegistration::class)->handle(
        registration: $registration,
        grants: [new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 1, resourceTypeIds: [], seatingMode: SeatingMode::Pick)],
    ));

    expect($errors)->toHaveKey('grants');
});

it('projects the categories, the mode and the registrant into RegistrationConfirmed', function (): void {
    $this->withBrand();
    $categoryId = (string) Str::ulid();
    $registrantId = (string) Str::ulid();

    $type = RegistrationType::factory()->withGrants([
        new GrantData(kind: GrantKind::Admission, role: 'visitor', quantity: 1),
        new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 2, resourceTypeIds: [$categoryId], seatingMode: SeatingMode::Pick),
    ])->create();
    $registration = Registration::factory()->create([
        'registration_type_id' => $type->id,
        'event_id' => $type->event_id,
        'registrant_user_id' => $registrantId,
    ]);

    Event::fake([RegistrationConfirmed::class]);

    app(ConfirmRegistration::class)->handle($registration);

    Event::assertDispatched(RegistrationConfirmed::class, function (RegistrationConfirmed $event) use ($registrantId, $categoryId): bool {
        expect($event->registrantUserId)->toBe($registrantId)
            ->and($event->grants)->toBe([
                [
                    'kind' => 'admission',
                    'role' => 'visitor',
                    'quantity' => 1,
                    'resource_type_id' => null,
                    'resource_type_ids' => null,
                    'seating_mode' => null,
                ],
                [
                    'kind' => 'seating',
                    'role' => 'seat',
                    'quantity' => 2,
                    'resource_type_id' => null,
                    'resource_type_ids' => [$categoryId],
                    'seating_mode' => 'pick',
                ],
            ]);

        return true;
    });
});

it('projects the same shape and the registrant into RegistrationAmended', function (): void {
    $this->withBrand();
    $categoryId = (string) Str::ulid();
    $registrantId = (string) Str::ulid();

    $registration = Registration::factory()->confirmed()->create(['registrant_user_id' => $registrantId]);

    Event::fake([RegistrationAmended::class]);

    $amendment = app(AmendRegistration::class)->handle(
        registration: $registration,
        grants: [new GrantData(kind: GrantKind::Seating, role: 'seat', quantity: 1, resourceTypeIds: [$categoryId], seatingMode: SeatingMode::Assign)],
    );

    Event::assertDispatched(
        RegistrationAmended::class,
        fn (RegistrationAmended $event): bool => $event->amendmentId === $amendment->id
            && $event->registrantUserId === $registrantId
            && $event->grants === [[
                'kind' => 'seating',
                'role' => 'seat',
                'quantity' => 1,
                'resource_type_id' => null,
                'resource_type_ids' => [$categoryId],
                'seating_mode' => 'assign',
            ]],
    );
});
