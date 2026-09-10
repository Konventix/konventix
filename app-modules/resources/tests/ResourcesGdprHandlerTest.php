<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Resources\Actions\SavePlan;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Gdpr\ResourcesGdprHandler;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\PlanVersion;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceType;

/**
 * Resources' contribution to a subject request (design §9.5, ADR-0050):
 * export the claims and bookings that point at the person and the plan
 * versions they authored; erase the references, keep the rows. A booking
 * is an event's occupancy history and a plan version is the operator's
 * work — neither is the person's, only the pointer at them is.
 *
 * Helper names prefixed `seating` (.ai/rules/tests.md).
 */
function seatingGdprFixture(string $subjectUserId): array
{
    $eventId = (string) Str::ulid();
    $type = ResourceType::factory()->forEvent($eventId)->create();
    $plan = Plan::factory()->create(['event_id' => $eventId, 'name' => 'Hall A', 'created_by' => $subjectUserId, 'published_at' => now()]);
    $place = Resource::factory()->ofType($type)->create(['plan_id' => $plan->id, 'ref' => 'A-01', 'label' => 'A-01', 'is_published' => true]);

    // One version authored by the subject, written through the real pipeline.
    app(SavePlan::class)->handle($plan, PlanSaveData::from([
        'expectedVersion' => $plan->version,
        'document' => ['schema' => 'konventix.plan/v1', 'elements' => []],
        'resolutions' => [],
    ]), $subjectUserId, PlanVersionSource::Editor);

    $claim = ResourceClaim::factory()->create([
        'event_id' => $eventId,
        'registration_id' => (string) Str::ulid(),
        'registrant_user_id' => $subjectUserId,
        'resource_type_ids' => [$type->id],
        'seating_mode' => SeatingMode::Pick,
        'quantity' => 1,
        'state' => ClaimState::Placed,
    ]);

    // holder_id mirrors registration_id for a registration-held booking, the
    // same convention AssignPlacesToRegistration writes (holderId set from
    // $locked->registration_id) — the deletion test below asserts erasure
    // leaves this pointer alone precisely because it is not a person id.
    $booking = ResourceBooking::factory()->create([
        'resource_id' => $place->id,
        'holder_id' => $claim->registration_id,
        'registration_id' => $claim->registration_id,
        'claim_id' => $claim->id,
        'label' => 'A-01',
    ]);

    $reservation = ResourceBooking::factory()->create([
        'resource_id' => Resource::factory()->ofType($type)->create(['plan_id' => $plan->id, 'ref' => 'A-02', 'label' => 'A-02'])->id,
        'holder_type' => ResourceBooking::HOLDER_OPERATOR,
        'holder_id' => $subjectUserId,
        'starts_at' => now()->startOfHour()->addDays(5),
        'ends_at' => now()->startOfHour()->addDays(6),
    ]);

    return compact('plan', 'claim', 'booking', 'reservation');
}

it('is registered with Compliance under its module name', function (): void {
    expect(app(GdprHandlerRegistry::class)->has('resources'))->toBeTrue()
        ->and(app(GdprHandlerRegistry::class)->get('resources'))->toBeInstanceOf(ResourcesGdprHandler::class);
});

it('exports the subject\'s claims, bookings, operator reservations and plan versions', function (): void {
    $this->withBrand();
    $subjectUserId = (string) Str::ulid();
    ['plan' => $plan, 'claim' => $claim, 'booking' => $booking, 'reservation' => $reservation] = seatingGdprFixture($subjectUserId);

    // Somebody else's claim and booking on the same event must not appear.
    seatingGdprFixture((string) Str::ulid());

    $export = (new ResourcesGdprHandler)->exportFor(SubjectRef::forUser($subjectUserId));

    expect($export['claims'])->toHaveCount(1)
        ->and($export['claims'][0]['id'])->toBe($claim->id)
        ->and($export['claims'][0]['state'])->toBe('placed')
        ->and(collect($export['bookings'])->pluck('id')->all())->toEqualCanonicalizing([$booking->id, $reservation->id]);

    $mine = collect($export['bookings'])->firstWhere('id', $booking->id);

    expect($mine['plan'])->toBe('Hall A')
        ->and($mine['ref'])->toBe('A-01')
        ->and($mine['label'])->toBe('A-01')
        ->and($mine['state'])->toBe('confirmed')
        ->and($mine['holder_type'])->toBe('registration')
        ->and($export['plan_versions'])->toHaveCount(1)
        ->and($export['plan_versions'][0]['plan'])->toBe('Hall A')
        ->and($export['plan_versions'][0]['plan_id'])->toBe($plan->id)
        ->and($export['plan_versions'][0]['version'])->toBe(1);
});

it('exports a plan the subject created even when nobody has saved it since', function (): void {
    // CreatePlan (the console's create dialog) writes no PlanVersion row —
    // that only happens on the first SavePlan (design §4.4). A plan whose
    // creator never went on to edit it must still surface as theirs.
    $this->withBrand();
    $subjectUserId = (string) Str::ulid();
    $plan = Plan::factory()->create(['name' => 'Untouched Hall', 'created_by' => $subjectUserId]);

    $export = (new ResourcesGdprHandler)->exportFor(SubjectRef::forUser($subjectUserId));

    expect($export['plans'])->toHaveCount(1)
        ->and($export['plans'][0]['id'])->toBe($plan->id)
        ->and($export['plans'][0]['name'])->toBe('Untouched Hall')
        ->and($export['plan_versions'])->toBe([]);
});

it('answers an email-only subject with nothing', function (): void {
    $this->withBrand();

    expect((new ResourcesGdprHandler)->exportFor(SubjectRef::forEmail('nobody@example.de')))->toBe([]);
});

it('nulls every reference to the subject and keeps the rows, in both modes', function (DeletionMode $mode): void {
    $this->withBrand();
    $subjectUserId = (string) Str::ulid();
    ['plan' => $plan, 'claim' => $claim, 'booking' => $booking, 'reservation' => $reservation] = seatingGdprFixture($subjectUserId);
    $otherUserId = (string) Str::ulid();
    ['claim' => $otherClaim] = seatingGdprFixture($otherUserId);

    (new ResourcesGdprHandler)->delete(SubjectRef::forUser($subjectUserId), $mode);
    // Idempotent: a re-dispatched request runs the fan-out again.
    (new ResourcesGdprHandler)->delete(SubjectRef::forUser($subjectUserId), $mode);

    expect($claim->refresh()->registrant_user_id)->toBeNull()
        ->and($claim->state)->toBe(ClaimState::Placed)
        ->and($plan->refresh()->created_by)->toBeNull()
        ->and(PlanVersion::query()->where('plan_id', $plan->id)->value('created_by'))->toBeNull()
        ->and($reservation->refresh()->holder_id)->toBeNull()
        ->and($reservation->state->value)->toBe('confirmed')
        ->and($booking->refresh()->holder_id)->toBe($claim->registration_id)
        ->and($otherClaim->refresh()->registrant_user_id)->toBe($otherUserId)
        ->and(ResourceBooking::query()->count())->toBe(4);
})->with([DeletionMode::Anonymize, DeletionMode::Erase]);
