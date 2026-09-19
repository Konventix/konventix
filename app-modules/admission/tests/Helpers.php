<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\RotateEntranceSigningKey;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceSigningKey;
use Konventix\Admission\Models\EventSummary;
use Konventix\Admission\Models\Person;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;

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
 * email is derived from the id rather than fixed, so a test creating
 * several people never collides on a possible unique index.
 */
function ticketPerson(array $overrides = []): Person
{
    $id = (string) Str::ulid();

    return Person::query()->create(array_merge([
        'id' => $id,
        'display_name' => 'Erika Musterfrau',
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
