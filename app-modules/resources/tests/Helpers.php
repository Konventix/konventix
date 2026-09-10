<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Events\EventCreated;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
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
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\Permission;
use Konventix\Resources\Enums\PlanVersionSource;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\Resource;
// Aliased: an unqualified "Resource" in a docblock generic collides with
// PHP's `resource` pseudo-type and pint's docblock fixer silently lowercases
// it (the same trap fixed in PlanDiffResult.php).
use Konventix\Resources\Models\Resource as BookableResource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Konventix\Resources\Models\ResourceType;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Spatie\Permission\PermissionRegistrar;

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
 * @return array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
 */
function seatingClaimGrant(string $resourceTypeId, int $quantity = 1): array
{
    return ['kind' => 'resource_claim', 'role' => 'booth', 'quantity' => $quantity, 'resource_type_id' => $resourceTypeId, 'resource_type_ids' => null, 'seating_mode' => null];
}

/**
 * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}>  $grants
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
        registrantUserId: (string) Str::ulid(),
        confirmedAt: now()->toIso8601String(),
        grants: $grants,
    ));

    return $registrationId;
}

/**
 * @param  list<array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}>  $grants
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
        registrantUserId: (string) Str::ulid(),
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
 * @return Collection<string, BookableResource>
 */
function seatingPlaces(Plan $plan): Collection
{
    /** @var Collection<string, BookableResource> $rows */
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

/**
 * A `kind: seating` grant in the payload shape of `RegistrationConfirmed`.
 *
 * @param  list<string>  $resourceTypeIds
 * @return array{kind: string, role: string, quantity: int, resource_type_id: string|null, resource_type_ids: list<string>|null, seating_mode: string|null}
 */
function seatingGrantPayload(array $resourceTypeIds, int $quantity = 1, string $seatingMode = 'pick'): array
{
    return [
        'kind' => 'seating',
        'role' => 'seat',
        'quantity' => $quantity,
        'resource_type_id' => null,
        'resource_type_ids' => $resourceTypeIds,
        'seating_mode' => $seatingMode,
    ];
}

/**
 * Dispatches a real `RegistrationConfirmed` so the module's real listener
 * wiring runs. Returns the registration id.
 *
 * @param  list<array<string, mixed>>  $grants
 */
function seatingConfirm(string $eventId, string $brandId, array $grants, ?string $registrationId = null, ?string $registrantUserId = null): string
{
    $registrationId ??= (string) Str::ulid();

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $registrantUserId ?? (string) Str::ulid(),
        confirmedAt: now()->toIso8601String(),
        grants: $grants,
    ));

    return $registrationId;
}

/**
 * Dispatches a real `RegistrationAmended`. Returns the amendment id.
 *
 * @param  list<array<string, mixed>>  $grants
 */
function seatingAmend(string $eventId, string $brandId, string $registrationId, array $grants, ?string $amendmentId = null, ?string $registrantUserId = null): string
{
    $amendmentId ??= (string) Str::ulid();

    event(new RegistrationAmended(
        registrationId: $registrationId,
        amendmentId: $amendmentId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $registrantUserId ?? (string) Str::ulid(),
        grants: $grants,
        amendedAt: now()->toIso8601String(),
    ));

    return $amendmentId;
}

/**
 * The registration's claim from its original confirmation (no amendment).
 */
function seatingClaimFor(string $registrationId): ResourceClaim
{
    return ResourceClaim::withoutBrandScope()
        ->where('registration_id', $registrationId)
        ->whereNull('amendment_id')
        ->sole();
}

/**
 * A place *row*: a unit-mode resource projected from a plan (seating design
 * §4.2), written directly instead of through `SavePlan`. The name is
 * deliberately not `seatingPlace()` — that one (Task 14) builds a document
 * element, and both live in the same process-global namespace.
 *
 * @param  array<string, mixed>  $attributes
 */
function seatingPlaceRow(Plan $plan, ResourceType $type, string $ref, array $attributes = []): Resource
{
    return Resource::factory()->ofType($type)->create(array_merge([
        'plan_id' => $plan->id,
        'ref' => $ref,
        'label' => $ref,
        'capacity' => 1,
        'is_published' => $plan->published_at !== null,
        'blocked' => false,
    ], $attributes));
}

/**
 * Runs an attempt that must be refused with a `ValidationException` and
 * hands back its errors, so a test asserts on the field and the text.
 *
 * @return array<string, array<int, string>>
 */
function seatingRefusal(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected a ValidationException and got none.');
}

/**
 * A user holding `resources.book-on-behalf` on the brand, granted the way
 * `permissions:sync` materialises it: a brand role plus a `model_has_roles`
 * row (the same shape `PermissionHoldersTest` uses).
 */
function seatingUserWithBookOnBehalf(string $brandId): User
{
    $user = User::factory()->create();

    PermissionModel::query()->firstOrCreate(['name' => Permission::BookOnBehalf->value, 'guard_name' => 'web']);

    $role = Role::query()->create([
        'name' => 'Seating desk '.Str::lower(Str::random(4)),
        'guard_name' => 'web',
        'brand_id' => $brandId,
    ]);
    $role->givePermissionTo([Permission::BookOnBehalf->value]);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

/**
 * An operator admin on its own admin host with the given capabilities.
 * The pattern is `registrationTypeOperator()` in Registration's suite;
 * the host differs so the two suites never share a unique `admin_host`.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function seatingOperator(array $permissions = [
    'platform.access-admin',
    'resources.manage-resource-types',
    'resources.manage-plans',
    'resources.book-on-behalf',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.seating.test'])->save();

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

/**
 * Projects an event window (with name and slug, design §4.7) by firing
 * Event's contract event — the only way this module may learn about an
 * event (ADR-0004). Takes the brand rather than creating it: a plain Pest
 * helper cannot call the protected `withBrand()` (.ai/rules/tests.md).
 */
function seatingEventWindow(Brand $brand, string $name = 'SXLan 2027', ?string $eventId = null): string
{
    $eventId ??= (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: $brand->getKey(),
        slug: Str::slug($name).'-'.Str::lower(Str::random(4)),
        name: $name,
        startsAt: '2027-05-14 18:00:00',
        endsAt: '2027-05-16 14:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return $eventId;
}

/**
 * A minimal valid `konventix.plan/v1` document (design §5): one group with
 * `$places` places of the given category, refs `A-01`…, so an import or a
 * save has something structurally complete to accept.
 *
 * @return array<string, mixed>
 */
function seatingPlanDocument(string $resourceTypeId, int $places = 2): array
{
    $groupId = (string) Str::ulid();
    $elements = [[
        'id' => $groupId,
        'kind' => 'group',
        'parentId' => null,
        'ref' => 'A',
        'label' => 'A',
        'layer' => 'default',
        'x' => 1000,
        'y' => 1000,
        'rotation' => 0,
        'shape' => ['type' => 'rect', 'w' => 800 * $places, 'h' => 800],
        'locked' => false,
    ]];

    for ($n = 1; $n <= $places; $n++) {
        $elements[] = [
            'id' => (string) Str::ulid(),
            'kind' => 'place',
            'parentId' => $groupId,
            'ref' => sprintf('A-%02d', $n),
            'label' => sprintf('A-%02d', $n),
            'layer' => 'default',
            'x' => 800 * ($n - 1),
            'y' => 0,
            'rotation' => 0,
            'shape' => ['type' => 'rect', 'w' => 800, 'h' => 800],
            'locked' => false,
            'resourceTypeId' => $resourceTypeId,
            'accessible' => false,
            'detached' => false,
            'blocked' => false,
        ];
    }

    return ['schema' => 'konventix.plan/v1', 'elements' => $elements];
}

/**
 * One zone, one group, `$places` places in a row — the document and its
 * projection written side by side, the way SavePlan leaves them: the place
 * element id is the `resources` row id (design §4.2).
 *
 * @return array{plan: Plan, places: list<BookableResource>}
 */
function seatingPublishedPlan(string $eventId, ResourceType $type, int $places = 2, bool $published = true): array
{
    $zoneId = (string) Str::ulid();
    $groupId = (string) Str::ulid();
    $placeIds = array_map(fn (): string => (string) Str::ulid(), range(1, $places));

    $elements = [
        [
            'id' => $zoneId, 'kind' => 'zone', 'parentId' => null, 'ref' => 'Z1', 'label' => 'Hall',
            'layer' => 'default', 'x' => 0, 'y' => 0, 'rotation' => 0,
            'shape' => ['type' => 'rect', 'w' => 10000, 'h' => 6000],
            'locked' => false, 'token' => 'chart-2', 'accessible' => false, 'detached' => false, 'blocked' => false,
        ],
        [
            'id' => $groupId, 'kind' => 'group', 'parentId' => $zoneId, 'ref' => 'A', 'label' => 'Row A',
            'layer' => 'default', 'x' => 1000, 'y' => 1000, 'rotation' => 0,
            'shape' => ['type' => 'rect', 'w' => 8000, 'h' => 800],
            'locked' => false, 'accessible' => false, 'detached' => false, 'blocked' => false,
        ],
    ];

    foreach ($placeIds as $index => $placeId) {
        $ref = sprintf('A-%02d', $index + 1);
        $elements[] = [
            'id' => $placeId, 'kind' => 'place', 'parentId' => $groupId, 'ref' => $ref, 'label' => $ref,
            'layer' => 'default', 'x' => $index * 900, 'y' => 0, 'rotation' => 0,
            'shape' => ['type' => 'rect', 'w' => 800, 'h' => 800],
            'locked' => false, 'resourceTypeId' => $type->id,
            'accessible' => false, 'detached' => false, 'blocked' => false,
        ];
    }

    $plan = new Plan;
    $plan->forceFill([
        'event_id' => $eventId,
        'venue_id' => null,
        'name' => 'Main hall '.Str::lower(Str::random(4)),
        'floor_label' => null,
        'width_mm' => 10000,
        'height_mm' => 6000,
        'background' => null,
        'unit_display' => 'cm',
        'snap_step' => 100,
        'version' => 1,
        'document' => PlanDocumentData::from(['schema' => 'konventix.plan/v1', 'elements' => $elements]),
        'published_at' => $published ? now() : null,
    ])->save();

    $rows = [];

    foreach ($placeIds as $index => $placeId) {
        $ref = sprintf('A-%02d', $index + 1);
        $place = Resource::factory()->ofType($type)->make(['label' => $ref, 'capacity' => 1]);
        $place->forceFill([
            'id' => $placeId,
            'plan_id' => $plan->getKey(),
            'ref' => $ref,
            'zone_id' => $zoneId,
            'group_id' => $groupId,
            'is_published' => $published,
            'accessible' => false,
            'blocked' => false,
        ])->save();
        $rows[] = $place;
    }

    return ['plan' => $plan, 'places' => $rows];
}

/**
 * @param  list<string>  $resourceTypeIds
 */
function seatingClaim(
    string $eventId,
    string $registrationId,
    string $userId,
    array $resourceTypeIds,
    int $quantity = 1,
    ClaimState $state = ClaimState::Unplaced,
): ResourceClaim {
    $claim = new ResourceClaim;
    $claim->forceFill([
        'event_id' => $eventId,
        'registration_id' => $registrationId,
        'registrant_user_id' => $userId,
        'amendment_id' => null,
        'resource_type_ids' => $resourceTypeIds,
        'seating_mode' => SeatingMode::Pick,
        'quantity' => $quantity,
        'state' => $state,
    ])->save();

    return $claim;
}

/**
 * A booking row as `AssignPlacesToRegistration` leaves it — holder is the
 * registration, interval is the event window, no clock (design §7.3).
 */
function seatingBooking(
    Resource $place,
    ResourceEventWindow $window,
    string $registrationId,
    ?ResourceClaim $claim = null,
    BookingState $state = BookingState::Confirmed,
): ResourceBooking {
    $booking = ResourceBooking::factory()->make([
        'resource_id' => $place->getKey(),
        'holder_type' => ResourceBooking::HOLDER_REGISTRATION,
        'holder_id' => $registrationId,
        'registration_id' => $registrationId,
        'quantity' => 1,
        'is_exclusive' => true,
        'state' => $state,
        'starts_at' => $window->starts_at,
        'ends_at' => $window->ends_at,
        'hold_expires_at' => null,
    ]);
    $booking->forceFill(['claim_id' => $claim?->getKey(), 'label' => $place->label])->save();

    return $booking;
}

/**
 * The registry as the test process sees it (`PROMETHEUS_STORAGE=memory` in
 * phpunit.xml): counters written by the listeners, without an HTTP round
 * trip. Gauges are only evaluated on a real scrape — see the next helper.
 */
function seatingMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

/**
 * A real `/metrics` scrape behind the health secret, so gauge closures run.
 */
function seatingMetricsScrape(): string
{
    config()->set('health.endpoint_secret', 'metrics-test-secret');

    return test()->withHeader('Authorization', 'Bearer metrics-test-secret')->get('/metrics')->assertOk()->getContent();
}

/**
 * An operator with its own admin host and the given capabilities, the
 * pattern of RegistrationTypeAdminTest: the capability rows are created by
 * hand because `permissions:sync` does not run inside a test.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function seatingEditorOperator(array $permissions = [
    'platform.access-admin',
    'resources.manage-plans',
    'resources.book-on-behalf',
]): array
{
    $operator = Operator::factory()->create();
    $operator->forceFill(['admin_host' => 'admin.seating-editor.test'])->save();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Seating Planner',
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId(null);

    return [$operator, $admin];
}

function seatingEditorEmptyDocument(): PlanDocumentData
{
    return PlanDocumentData::from(['schema' => 'konventix.plan/v1', 'elements' => []]);
}

/**
 * One group at (1000, 1000) with `count($placeIds)` places 800 mm apart,
 * labelled A-01, A-02, … — the smallest document the projection, the
 * conflict list and the editor all have something to say about.
 *
 * @param  list<string>  $placeIds
 */
function seatingEditorDocument(string $groupId, array $placeIds, string $categoryId): PlanDocumentData
{
    $elements = [[
        'id' => $groupId,
        'kind' => 'group',
        'parentId' => null,
        'label' => 'A',
        'x' => 1000,
        'y' => 1000,
        'shape' => ['type' => 'point'],
        'layout' => ['type' => 'linear', 'count' => count($placeIds), 'spacing' => 800, 'startIndex' => 1],
        'labelSchema' => ['row' => '{row}', 'place' => '{row}-{n:02}'],
    ]];

    foreach (array_values($placeIds) as $index => $placeId) {
        $label = sprintf('A-%02d', $index + 1);

        $elements[] = [
            'id' => $placeId,
            'kind' => 'place',
            'parentId' => $groupId,
            'ref' => $label,
            'label' => $label,
            'x' => $index * 800,
            'y' => 0,
            'shape' => ['type' => 'rect', 'w' => 800, 'h' => 800],
            'resourceTypeId' => $categoryId,
        ];
    }

    return PlanDocumentData::from(['schema' => 'konventix.plan/v1', 'elements' => $elements]);
}

/**
 * A plan of the ambient brand with its places already projected into
 * `resources` through the real save pipeline, so ids equal resource ids.
 *
 * @return array{0: Plan, 1: list<string>, 2: string}
 */
function seatingEditorPlan(Brand $brand, string $eventId, ResourceType $category, int $places = 2): array
{
    $groupId = (string) Str::ulid();
    $placeIds = [];

    for ($index = 0; $index < $places; $index++) {
        $placeIds[] = (string) Str::ulid();
    }

    $plan = Plan::factory()->create([
        'event_id' => $eventId,
        'name' => 'Hall '.Str::lower(Str::random(4)),
        'width_mm' => 20000,
        'height_mm' => 10000,
        'document' => seatingEditorEmptyDocument(),
    ]);

    app(SavePlan::class)->handle(
        $plan,
        PlanSaveData::from([
            'expectedVersion' => $plan->version,
            'document' => seatingEditorDocument($groupId, $placeIds, $category->getKey()),
            'resolutions' => [],
        ]),
        null,
        PlanVersionSource::Editor,
    );

    return [$plan->refresh(), $placeIds, $groupId];
}
