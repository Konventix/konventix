<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceDevice;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Admission\Models\RegistrationSummary;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for this feature's admission tests (#72). Every function
 * here is process-global (.ai/rules/tests.md) and prefixed `ticket` — the
 * admission module already owns `badgeTestOperator()`, file-local to
 * BadgeDocumentTest.php, so this prefix keeps these names apart from that
 * one and from every other module's Helpers.php the suite loads into the
 * same worker.
 *
 * None of these set the ambient brand context — call `$this->withBrand()`
 * with the `Brand` a helper hands back, the way every other module's
 * Helpers.php does; a plain `function` cannot reach the protected
 * `TestCase::withBrand()` itself.
 */

/**
 * A brand tied to a specific operator, for tests that need to control what
 * the ticket footer's operator line and the forensic watermark's legal-name
 * line say. `$this->withBrand()` alone would work too, but its operator is
 * whichever random one `BrandFactory` reuses or mints — not something a
 * ticket test can assert against on purpose.
 */
function ticketOperatorBrand(array $operatorAttributes = [], array $brandAttributes = []): Brand
{
    $operator = Operator::factory()->create($operatorAttributes);

    return Brand::factory()->for($operator)->create($brandAttributes);
}

/**
 * Admission's own read model of one event (§13 amendment "planning: the
 * calendar builder moves to Platform..."), keyed by `[brand_id, event_id]`
 * the way `registration_event_summaries` is. No factory ships with the
 * model — it exists only through its own projector — so tests build the
 * row directly, the same way Registration's `Registrant` and Messaging's
 * `Recipient` rows are built by the modules that read Identity's
 * projection.
 */
function ticketEventSummary(Brand $brand, string $eventId, array $overrides = []): EventSummary
{
    $summary = new EventSummary(array_merge([
        'event_id' => $eventId,
        'name' => 'Kon Fest 2026',
        'slug' => 'kon-fest-2026',
        'starts_at' => Carbon::parse('2026-10-16 10:00:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2026-10-18 18:00:00', 'Europe/Berlin'),
        'timezone' => 'Europe/Berlin',
        'venue_label' => 'Halle 3, Musterstadt',
    ], $overrides));

    // Explicit brand (ADR-0010), not the mass-assignable set: `brand_id` is
    // deliberately absent from EventSummary::$fillable — BelongsToBrand
    // stamps it from ambient context on `creating`, never from caller
    // input — so `create([...'brand_id'...])` throws under
    // Model::shouldBeStrict(). Mirrors IssueAdmissionToken::rotate()'s
    // `$entry->forceFill(['brand_id' => ...])->save()`.
    $summary->forceFill(['brand_id' => $brand->id])->save();

    return $summary;
}

/**
 * A holder, projected the way Admission's own people read model is (§13
 * amendment "the holder-name fallback drops the billing recipient"). No
 * factory ships with the model, matching `Registrant`/`Recipient`. The
 * email and the handle are derived from the id rather than fixed, so a
 * test creating several people never collides on a possible unique
 * index; a test asserting a handle passes its own.
 */
function ticketPerson(array $overrides = []): Person
{
    $id = (string) Str::ulid();

    return Person::query()->create(array_merge([
        'id' => $id,
        'display_name' => 'Erika Musterfrau',
        'username' => 'holder-'.strtolower(substr($id, -8)),
        'email' => sprintf('holder+%s@example.test', strtolower($id)),
    ], $overrides));
}

/**
 * One admission for the given event, holding either a specific `$holder`
 * or none at all — pass `null` for the unclaimed case the badge already
 * prints as "Unassigned" and the ticket must too.
 */
function ticketAdmissionFor(string $eventId, ?Person $holder = null, array $overrides = []): Admission
{
    return Admission::factory()->create(array_merge([
        'event_id' => $eventId,
        'holder_user_id' => $holder?->id,
    ], $overrides));
}

/**
 * An active Ed25519 signing key for the brand (ADR-0033) — every render
 * that reaches `IssueAdmissionToken::current()` needs one, or minting
 * fails loudly with "run admission:rotate-signing-key first".
 */
function ticketSigningKey(Brand $brand): EntranceSigningKey
{
    return app(RotateEntranceSigningKey::class)->handle($brand->id);
}

/**
 * A minimal `$page` array shaped like `TicketDocumentRenderer::
 * pageDataFor()`'s return value, for a test that renders the ticket
 * Blade view directly (`view('admission::tickets.document', [...])
 * ->render()`) rather than going through the renderer and dompdf — the
 * watermark layers' document order (§4.3) is a property of the view's
 * markup, not of anything the renderer or the database contributes, so
 * a test pinning it needs no brand, admission or database row at all.
 */
function ticketPageFixture(array $overrides = []): array
{
    return array_merge([
        'brandLogo' => null,
        'eventName' => 'Kon Fest 2026',
        'eventWhen' => '16.10.2026 10:00 – 18.10.2026 18:00 (Europe/Berlin)',
        'venueLabel' => 'Halle 3, Musterstadt',
        'holderName' => 'Erika Musterfrau',
        'role' => 'crew',
        'validRange' => 'All event days',
        'seatLabel' => null,
        // ADR-0066 §9.3: what the door will ask for, printed beside the
        // holder. Empty by default — most tickets carry no condition.
        'entryConditions' => [],
        'banner' => null,
        'doorCode' => 'ABCD-1234',
        'qr' => 'data:image/svg+xml;base64,',
        'operatorLegalName' => 'Konventix Dev Operator',
        'brandName' => 'SXLan',
        'admissionId' => (string) Str::ulid(),
        'watermarkHtml' => '<div class="brandmark">brand mark</div>',
        'testSystemHtml' => '<div class="testsystem">TESTSYSTEM</div>',
    ], $overrides);
}

/**
 * A user holding one Admission capability on the brand, granted the way
 * `permissions:sync` materialises it — a brand role plus a `model_has_roles`
 * row — which is the shape `PermissionHolders` reads (#292). Mirrors
 * Resources' `seatingUserWithBookOnBehalf()`.
 */
function admissionUserWithPermission(string $brandId, string $permission): User
{
    $user = User::factory()->create();

    PermissionModel::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

    $role = Role::query()->create([
        'name' => 'Desk '.Str::lower(Str::random(4)),
        'guard_name' => 'web',
        'brand_id' => $brandId,
    ]);
    $role->givePermissionTo([$permission]);

    DB::table('model_has_roles')->insert([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'brand_id' => $brandId,
    ]);

    return $user;
}

/**
 * One enrolled door: a checkpoint on an event, a device holding a plain
 * bearer token, and the event's id (ADR-0033). Prefixed and here rather than
 * in a test file because two files call it (.ai/rules/tests.md).
 *
 * @return array{device: EntranceDevice, checkpoint: Checkpoint, token: string, eventId: string}
 */
function entranceTestLane(?string $eventId = null): array
{
    $eventId ??= (string) Str::ulid();

    $checkpoint = Checkpoint::factory()->forEvent($eventId)->create();
    $plain = Str::random(48);
    $device = EntranceDevice::factory()
        ->withToken($plain)
        ->create(['checkpoint_id' => $checkpoint->id]);

    return ['device' => $device, 'checkpoint' => $checkpoint, 'token' => $plain, 'eventId' => $eventId];
}

function entranceTestHeaders(string $plainToken): array
{
    return ['Authorization' => 'Bearer '.$plainToken, 'Accept' => 'application/json'];
}

/**
 * One accepted scan for `$admission`, shaped the way `RecordScan` emits it
 * (design §4.2): `AdmissionCheckedIn` carries no day or checkpoint here
 * because the presence projection reads neither — it resolves the day from
 * `EntranceEventProfile` by the scan instant. Shared by the presence
 * projection, hub-access and sweep tests (.ai/rules/tests.md), so it lives
 * here and not in any one of them.
 */
function presenceScan(Admission $admission, string $direction = 'in', string $at = '2026-10-16T20:00:00+02:00'): AdmissionCheckedIn
{
    return new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admission->id,
        registrationId: $admission->registration_id ?? (string) Str::ulid(),
        eventId: $admission->event_id,
        brandId: $admission->brand_id,
        eventDayId: null,
        checkpointId: null,
        direction: $direction,
        scannedAt: $at,
    );
}

/**
 * A console account on the brand's operator host for the attendee list
 * (#329), holding the given capabilities on an operator-scoped role — the
 * admin host resolves permissions against the operator's team (ADR-0029).
 * The operator is the brand's own unless another is passed; `$host` must be
 * distinct per operator (`operators.admin_host` is unique). Every brand-owned
 * row a test needs must exist before its first admin-host request
 * (.ai/rules/tests.md).
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function attendeeConsoleOperator(
    Brand $brand,
    array $permissions = ['platform.access-admin', 'admission.check-in-attendees'],
    ?Operator $operator = null,
    string $host = 'admin.attendees.test',
): array {
    $operator ??= Operator::query()->findOrFail($brand->operator_id);
    $operator->forceFill(['admin_host' => $host])->save();

    $registrar = app(PermissionRegistrar::class);
    $previousTeam = $registrar->getPermissionsTeamId();
    $registrar->setPermissionsTeamId($operator->getKey());

    foreach ($permissions as $name) {
        PermissionModel::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->create([
        'name' => 'Attendee desk '.Str::lower(Str::random(4)),
        'guard_name' => 'web',
        'brand_id' => $operator->getKey(),
    ]);
    $role->givePermissionTo($permissions);

    $admin = User::factory()->create();
    $admin->assignRole($role);

    $registrar->setPermissionsTeamId($previousTeam);
    $registrar->forgetCachedPermissions();

    return [$operator, $admin];
}

/**
 * An event the attendee list can show (#329): Admission's event summary and
 * an entrance profile with one calendar day, 2026-10-16, so a test frozen on
 * that date reads "in" on that day (`Support\LatestAcceptedCheckIn`).
 *
 * @return array{eventId: string, dayId: string}
 */
function attendeeFixture(Brand $brand, string $name = 'Kon Fest 2026'): array
{
    $eventId = (string) Str::ulid();
    $dayId = (string) Str::ulid();

    ticketEventSummary($brand, $eventId, ['name' => $name]);
    EntranceEventProfile::factory()
        ->policy(ReentryPolicy::Unlimited)
        ->withDays([['day_id' => $dayId, 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag']])
        ->create(['event_id' => $eventId]);

    return ['eventId' => $eventId, 'dayId' => $dayId];
}

/**
 * One ticket of the event, with an optional holder and buyer, and — when
 * `$typeName` is given — Admission's registration summary for it, written
 * the way `ProjectRegistrationSummary` would (the brand explicitly, never
 * mass-assigned).
 */
function attendeeTicket(
    Brand $brand,
    string $eventId,
    ?Person $holder = null,
    ?Person $buyer = null,
    ?string $typeName = null,
    string $status = 'confirmed',
    array $overrides = [],
): Admission {
    $admission = Admission::factory()->create(array_merge([
        'event_id' => $eventId,
        'holder_user_id' => $holder?->id,
        'buyer_user_id' => $buyer?->id,
    ], $overrides));

    if ($typeName !== null && $admission->registration_id !== null) {
        (new RegistrationSummary(['registration_id' => $admission->registration_id, 'type_name' => $typeName, 'status' => $status]))
            ->forceFill(['brand_id' => $brand->id])
            ->save();
    }

    return $admission;
}
