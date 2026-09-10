<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Konventix\Event\Events\EventCreated;
use Konventix\Platform\Models\Brand;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Resources\Actions\ConfirmResourceBooking;
use Konventix\Resources\Actions\HoldResourceBooking;
use Konventix\Resources\Actions\SavePlan;
use Konventix\Resources\Data\PlanConflictResolutionData;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Data\PlanSaveData;
use Konventix\Resources\Data\PlanSaveResultData;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run; names are process-global across all
 * modules and therefore prefixed `seating` (.ai/rules/tests.md). Helpers
 * declared inside a *Test.php (`bookingTestHold`, `claimTestWindow`) stay
 * private to that file.
 *
 * This file is created here, once, and only ever appended to: every later
 * slice adds functions with names no other slice uses. A redeclared name is
 * a fatal error in every worker, because Pest requires this file once per
 * process.
 */

/**
 * Projects an event window through the real `EventCreated` listener.
 * Wall-clock strings plus an IANA zone, as Event emits them (ADR-0027).
 */
function seatingProjectWindow(
    string $eventId,
    string $brandId,
    string $startsAt = '2027-06-15 10:00:00',
    string $endsAt = '2027-06-17 18:00:00',
    string $timezone = 'Europe/Berlin',
    string $name = 'Seating test event',
    ?string $slug = null,
): ResourceEventWindow {
    event(new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: $slug ?? 'e-'.Str::lower(Str::random(6)),
        name: $name,
        startsAt: $startsAt,
        endsAt: $endsAt,
        timezone: $timezone,
        predecessorEventId: null,
    ));

    return ResourceEventWindow::withoutBrandScope()
        ->where('brand_id', $brandId)
        ->where('event_id', $eventId)
        ->firstOrFail();
}

/**
 * The event window of an event, projected through the real `EventCreated`
 * listener when it is not there yet. Called with the brand id by every task
 * that starts from a fresh event id (S3–S5), and with the event id alone by
 * the plan tests (S2), which project the window through `seatingEvent()`
 * first.
 */
function seatingWindow(string $eventId, ?string $brandId = null): ResourceEventWindow
{
    $existing = ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->first();

    if ($existing !== null) {
        return $existing;
    }

    if ($brandId === null) {
        throw new RuntimeException(sprintf('No resource event window for event [%s] and no brand id to project one.', $eventId));
    }

    event(new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: 'e-'.Str::lower(Str::random(6)),
        name: 'Seating test event',
        startsAt: now()->startOfHour()->addDay()->format('Y-m-d H:i:s'),
        endsAt: now()->startOfHour()->addDays(3)->format('Y-m-d H:i:s'),
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return ResourceEventWindow::withoutBrandScope()->where('event_id', $eventId)->firstOrFail();
}

function seatingUnitType(string $eventId): ResourceType
{
    return ResourceType::factory()->forEvent($eventId)->create();
}

function seatingPublishedResource(ResourceType $type, string $label): Resource
{
    return Resource::factory()->published()->ofType($type)->create(['label' => $label]);
}

/**
 * @return array{kind: string, role: string, quantity: int, resource_type_id: string|null}
 */
function seatingClaimGrant(string $resourceTypeId, int $quantity = 1): array
{
    return ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => $quantity, 'resource_type_id' => $resourceTypeId];
}

/**
 * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
 * @return string the registration id
 */
function seatingConfirmRegistration(string $eventId, string $brandId, array $grants, ?string $registrationId = null): string
{
    $registrationId ??= (string) Str::ulid();

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        confirmedAt: now()->toIso8601String(),
        grants: $grants,
    ));

    return $registrationId;
}

/**
 * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null}>  $grants
 * @return string the amendment id
 */
function seatingAmendRegistration(string $eventId, string $brandId, array $grants, string $registrationId, ?string $amendmentId = null): string
{
    $amendmentId ??= (string) Str::ulid();

    event(new RegistrationAmended(
        registrationId: $registrationId,
        amendmentId: $amendmentId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        grants: $grants,
        amendedAt: now()->toIso8601String(),
    ));

    return $amendmentId;
}

function seatingCancelRegistration(string $registrationId): void
{
    event(new RegistrationCancelled(
        registrationId: $registrationId,
        cancelledByUserId: (string) Str::ulid(),
        reasonCode: 'registrant_request',
        cancelledAt: now()->toIso8601String(),
    ));
}

function seatingRejectRegistration(string $registrationId): void
{
    event(new RegistrationRejected(
        registrationId: $registrationId,
        rejectedByUserId: (string) Str::ulid(),
        reasonCode: 'ineligible',
        rejectedAt: now()->toIso8601String(),
    ));
}

/**
 * A clocked hold over the whole event window, the way a direct booking
 * (not a claim) makes one. `registrationId` links it to a registration.
 */
function seatingHold(Resource $resource, ResourceEventWindow $window, ?string $registrationId = null): ResourceBooking
{
    return app(HoldResourceBooking::class)->handle(
        resource: $resource,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: $registrationId ?? (string) Str::ulid(),
        startsAt: $window->starts_at,
        endsAt: $window->ends_at,
        registrationId: $registrationId,
    );
}

/**
 * One element of a plan document (design §5) as the array the editor sends:
 * every key present, so a Data validation failure is about the value under
 * test and never about a missing key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seatingRawElement(string $kind, array $overrides = []): array
{
    return array_replace([
        'id' => (string) Str::ulid(),
        'kind' => $kind,
        'parentId' => null,
        'ref' => null,
        'label' => null,
        'layer' => 'default',
        'x' => 0,
        'y' => 0,
        'rotation' => 0,
        'shape' => ['type' => 'rect', 'w' => 800, 'h' => 800, 'radius' => null, 'rx' => null, 'ry' => null, 'points' => null],
        'locked' => false,
        'originId' => null,
        'token' => null,
        'layout' => null,
        'labelSchema' => null,
        'resourceTypeId' => null,
        'accessible' => false,
        'detached' => false,
        'blocked' => false,
        'fixtureType' => null,
        'text' => null,
        'fontSizeMm' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seatingRawPlace(string $resourceTypeId, array $overrides = []): array
{
    return seatingRawElement('place', array_replace(['resourceTypeId' => $resourceTypeId], $overrides));
}

/**
 * @param  list<array<string, mixed>>  $elements
 * @return array{schema: string, elements: list<array<string, mixed>>}
 */
function seatingRawDocument(array $elements): array
{
    return ['schema' => PlanDocumentData::SCHEMA, 'elements' => array_values($elements)];
}

/**
 * Projects an event window through Event's contract event (Resources may not
 * read Event's models, ADR-0004) and returns the event id.
 */
function seatingEvent(Brand $brand): string
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: $brand->id,
        slug: 'seating-'.Str::lower(Str::random(6)),
        name: 'Seating test event',
        startsAt: '2027-05-14 18:00:00',
        endsAt: '2027-05-16 14:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return $eventId;
}

/**
 * A plan category: a `ResourceType` of the event with the console's display
 * columns filled in. `token` and `symbol` are projection columns S1 added,
 * so they are written past `$fillable` with `forceFill()`.
 */
function seatingCategory(string $eventId, string $slug = 'seat', string $token = 'chart-1', ?string $symbol = 'armchair'): ResourceType
{
    $type = ResourceType::factory()->forEvent($eventId)->make([
        'name' => Str::title($slug),
        'slug' => $slug,
    ]);

    $type->forceFill(['token' => $token, 'symbol' => $symbol, 'sort_order' => 0])->save();

    return $type;
}

/**
 * A plan row with an empty document. `forceFill` on purpose: the helper must
 * not depend on which columns S1 listed as fillable.
 *
 * @param  array<string, mixed>  $attributes
 */
function seatingPlan(string $eventId, array $attributes = []): Plan
{
    $plan = new Plan;
    $plan->forceFill(array_merge([
        'event_id' => $eventId,
        'venue_id' => null,
        'name' => 'Hall '.Str::upper(Str::random(4)),
        'floor_label' => null,
        'width_mm' => 40000,
        'height_mm' => 30000,
        'unit_display' => 'cm',
        'snap_step' => 100,
        'version' => 0,
        'document' => seatingDocument([]),
        'published_at' => null,
    ], $attributes))->save();

    return $plan;
}

/**
 * The element builders below all go through Task 11's `seatingRawElement()`,
 * which fills every key of design §5 — a Data validation failure is then
 * always about the value under test, never about a missing key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seatingZone(array $overrides = []): array
{
    return seatingRawElement('zone', array_merge([
        'ref' => 'zone-'.Str::lower(Str::random(4)),
        'label' => 'Hall',
        'shape' => ['type' => 'rect', 'w' => 20000, 'h' => 20000],
        'token' => 'chart-1',
    ], $overrides));
}

/**
 * A linear group with spacing 800, so `PlanNeighbors`' tolerance is 200 mm.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seatingGroup(array $overrides = []): array
{
    return seatingRawElement('group', array_merge([
        'ref' => 'group-'.Str::lower(Str::random(4)),
        'label' => 'A',
        'shape' => ['type' => 'rect', 'w' => 6400, 'h' => 800],
        'layout' => ['type' => 'linear', 'count' => 8, 'spacing' => 800, 'startIndex' => 1],
        'labelSchema' => ['row' => '{row}', 'place' => '{row}-{n:02}'],
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seatingPlace(string $resourceTypeId, string $ref, array $overrides = []): array
{
    return seatingRawElement('place', array_merge([
        'ref' => $ref,
        'label' => $ref,
        'resourceTypeId' => $resourceTypeId,
        'accessible' => false,
        'detached' => false,
        'blocked' => false,
    ], $overrides));
}

/**
 * @param  list<array<string, mixed>>  $elements
 */
function seatingDocument(array $elements): PlanDocumentData
{
    return PlanDocumentData::from(seatingRawDocument($elements));
}

/**
 * Saves through the real Action with the plan's current version as the
 * expected one. SavePlan updates the passed instance, so a second call on
 * the same `$plan` is not stale.
 *
 * @param  list<PlanConflictResolutionData>  $resolutions
 */
function seatingSave(Plan $plan, PlanDocumentData $document, array $resolutions = [], ?string $actorUserId = null): PlanSaveResultData
{
    return app(SavePlan::class)->handle(
        $plan,
        new PlanSaveData(expectedVersion: $plan->version, document: $document, resolutions: $resolutions),
        $actorUserId,
        PlanVersionSource::Editor,
    );
}

/**
 * The plan's projection rows, soft-deleted ones included, keyed by id.
 *
 * @return Collection<string, resource>
 */
function seatingPlaces(Plan $plan): Collection
{
    /** @var Collection<string, resource> $rows */
    $rows = Resource::withoutBrandScope()->withTrashed()->where('plan_id', $plan->id)->get()->keyBy('id');

    return $rows;
}

/**
 * Occupies a place the way the picker will (S3): a placed claim and a
 * confirmed registration booking that points at it. Goes through the
 * booking Actions; only `claim_id` is stamped afterwards, so the helper
 * does not depend on the S0 signature of HoldResourceBooking.
 */
function seatingOccupy(Resource $place, ?string $registrationId = null): ResourceBooking
{
    $registrationId ??= (string) Str::ulid();

    $type = ResourceType::withoutBrandScope()->whereKey($place->resource_type_id)->firstOrFail();
    $window = seatingWindow($type->event_id);

    $claim = new ResourceClaim;
    $claim->forceFill([
        'event_id' => $type->event_id,
        'registration_id' => $registrationId,
        'registrant_user_id' => null,
        'amendment_id' => null,
        'resource_type_ids' => [$type->id],
        'seating_mode' => SeatingMode::Pick->value,
        'quantity' => 1,
        'state' => ClaimState::Placed->value,
    ])->save();

    $booking = app(HoldResourceBooking::class)->handle(
        resource: $place,
        holderType: ResourceBooking::HOLDER_REGISTRATION,
        holderId: $registrationId,
        startsAt: $window->starts_at,
        endsAt: $window->ends_at,
        quantity: 1,
        registrationId: $registrationId,
    );
    $booking = app(ConfirmResourceBooking::class)->handle($booking);
    $booking->forceFill(['claim_id' => $claim->id])->save();

    return $booking->refresh();
}
