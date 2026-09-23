<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;

/**
 * Helpers that more than one file of this module's suite calls. Pest loads
 * every module's tests/Helpers.php from tests/Pest.php, so they exist in
 * every worker of a parallel run; names are process-global across all
 * modules and therefore prefixed `competition` (.ai/rules/tests.md).
 * Helpers declared inside a *Test.php (`compTestEligible`) stay private to
 * that file.
 *
 * This file is created here, once, and only ever appended to: a redeclared
 * name is a fatal error in every worker.
 */

/**
 * An operator with an admin host and a console user holding the given
 * capabilities. Competition's console lives on the operator host (ADR-0029),
 * where no brand resolves — every test that needs a brand-owned row after
 * such a request creates it first.
 *
 * The operator is **the installation's one operator** — the same one
 * `BrandFactory` hangs every brand on, whichever of the two was created
 * first — because every console controller answers 404 for a brand of
 * another operator (`ScopesToOperator`). Pass `$operator` and a distinct
 * `$host` for somebody else's installation: `operators.admin_host` is unique.
 *
 * **The console honours the module gate** (design §9, `ScopesToOperator`): a
 * brand with `competition` off counts as a stranger's and its rows answer
 * 404. So this switches the module on for every brand the operator already
 * has, which is every brand a console test made before calling it. Pass
 * `activateCompetition: false` for a test about the gate itself.
 *
 * @param  list<string>  $permissions
 * @return array{0: Operator, 1: User}
 */
function competitionOperator(array $permissions = [
    'platform.access-admin',
    'competition.view-competitions',
    'competition.manage-competitions',
    'competition.manage-disciplines',
], ?Operator $operator = null, string $host = 'admin.tournaments.test', bool $activateCompetition = true): array
{
    $operator ??= Operator::query()->orderBy('created_at')->orderBy('id')->first() ?? Operator::factory()->create();
    $operator->forceFill(['admin_host' => $host])->save();

    if ($activateCompetition) {
        $feature = app(ModuleGate::class)->featureName('competition');

        foreach (Brand::query()->where('operator_id', $operator->getKey())->get() as $brand) {
            Feature::for($brand)->activate($feature);
        }
    }

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
 * Stands in for whichever module owns events: the console's event selector
 * is fed through Platform's `ConsoleEventSources`, and Competition keeps no
 * event read model of its own (ADR-0004). Returns the event's ULID.
 */
function competitionConsoleEvent(Brand $brand, string $name = 'SXLan 2027', ?string $eventId = null): string
{
    $eventId ??= (string) Str::ulid();

    app(ConsoleEventSources::class)->add(static fn (?string $brandId): array => [
        ['id' => $eventId, 'name' => $name, 'brandId' => (string) $brand->getKey()],
    ]);

    return $eventId;
}

/**
 * Somebody who may enter (design §5.1, D-CMP-05): a confirmed registration
 * for the event, arriving the only sanctioned way — through Registration's
 * contract events, never by writing Competition's read model directly
 * (ADR-0004, ADR-0006). Returns the user's ULID.
 *
 * Lives here rather than in a test file because two of this module's files
 * call it: under `--parallel` each worker loads only the files it was handed,
 * so a helper declared in one `*Test.php` does not exist in the worker
 * running the other (.ai/rules/tests.md).
 */
function competitionEligibleUser(string $eventId, string $brandId, ?string $userId = null): string
{
    $userId ??= (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    event(new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'on_submit',
        submittedAt: now()->toIso8601String(),
    ));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));

    return $userId;
}

/**
 * A name in Competition's own read model (design §10), projected the only
 * way it ever is: from Identity's `UserProfileUpdated`. No test in this
 * module writes `competition_participant_profiles` by hand, and none imports
 * Identity's `User` — which is exactly what the third acceptance criterion of
 * #297 asks for.
 */
function competitionParticipantName(string $userId, string $displayName, string $username): void
{
    event(new UserProfileUpdated(
        userId: $userId,
        email: $username.'@example.test',
        displayName: $displayName,
        username: $username,
        tagline: null,
        statusEmoji: null,
        socialLinks: [],
        visibility: 'internal',
        listedInAttendees: true,
        locale: 'de',
        updatedAt: now()->toIso8601String(),
    ));
}

/**
 * A competition, a field of `$entrants` entrants seeded 1..n, and a real
 * roster behind each one (design §8, §11).
 *
 * The rosters are the point. Reporting, confirming and disputing are
 * membership rights rather than capabilities (§11), so a test of §8 that used
 * entrants without members would prove the happy path only by skipping the
 * guard that decides who may speak for a side. One member per team, made
 * captain, because §8 never asks for more than one.
 *
 * Lives here rather than in a test file because both of §8's test files call
 * it: under `--parallel` each worker loads only the files it was handed
 * (.ai/rules/tests.md).
 *
 * @param  array<string, mixed>  $competitionAttributes
 * @return array{0: Competition, 1: list<Entrant>, 2: array<string, string>} the
 *                                                                           competition, the field in seed order, and entrant ULID => its member's user ULID
 */
function competitionFieldWithRosters(int $entrants, array $competitionAttributes = []): array
{
    // `SignupClosed` unless the caller says otherwise: the field is made
    // here, not through signup, and §4 draws the bracket only once signup is
    // closed (`GuardsCompetitionLifecycle`).
    $competition = Competition::factory()->create(['status' => CompetitionStatus::SignupClosed, ...$competitionAttributes]);

    $field = [];
    $members = [];

    for ($seed = 1; $seed <= $entrants; $seed++) {
        $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

        $userId = (string) Str::ulid();

        $member = new CompetitionTeamMember([
            'competition_team_id' => $team->getKey(),
            'user_id' => $userId,
            'role' => TeamRole::Captain,
        ]);
        $member->forceFill(['brand_id' => $team->brand_id])->save();

        $entrant = Entrant::factory()->create([
            'competition_id' => $competition->getKey(),
            'competition_team_id' => $team->getKey(),
            'seed' => $seed,
        ]);

        $field[] = $entrant;
        $members[$entrant->getKey()] = $userId;
    }

    return [$competition, $field, $members];
}

/**
 * A generated stage over a competition's field: the bracket, its slots and
 * its progression graph, exactly as the desk would draw it.
 */
function competitionGeneratedStage(Competition $competition, string $format = 'single_elimination'): Stage
{
    $stage = Stage::factory()->format($format)->create(['competition_id' => $competition->getKey()]);

    app(GenerateBracket::class)->handle($stage);

    return $stage->refresh();
}

/**
 * One match of a stage, by the coordinates the generator gave it.
 */
function competitionMatchAt(Stage $stage, int $round, int $position): CompetitionMatch
{
    /** @var CompetitionMatch $match */
    $match = CompetitionMatch::query()
        ->where('stage_id', $stage->getKey())
        ->where('round', $round)
        ->where('position', $position)
        ->firstOrFail();

    return $match;
}

/**
 * A match's sides as `position => entrant ULID`, unfilled slots left out.
 *
 * Tests read the sides out rather than assuming which seed the generator put
 * where: the seeding construction is `BracketMathematicsTest`'s subject, and a
 * §8 test that hard-coded it would fail for the wrong reason if it ever
 * changed.
 *
 * @return array<int, string>
 */
function competitionSidesOf(CompetitionMatch $match): array
{
    $sides = [];

    foreach (MatchSlot::query()->where('competition_match_id', $match->getKey())->orderBy('position')->get() as $slot) {
        if ($slot->entrant_id !== null) {
            $sides[$slot->position] = (string) $slot->entrant_id;
        }
    }

    return $sides;
}

/**
 * An event Competition knows to be public (design §10), learned the only way
 * it ever is: Event's `EventCreated`, then `EventPublished` (ADR-0004). Every
 * participant write refuses a competition of an event that is not public, the
 * tournament pages' own rule (final review fix wave B, item 3), so a test of
 * such a write needs one. Returns the event's ULID; pass one to publish an
 * event a competition already points at. Idempotent per brand and event.
 */
function competitionPublicEvent(string $brandId, ?string $eventId = null): string
{
    $eventId ??= (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: $brandId,
        slug: 'event-'.strtolower($eventId),
        name: 'SXLan '.substr($eventId, -4),
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    event(new EventPublished(
        eventId: $eventId,
        brandId: $brandId,
        publishedAt: '2026-10-01T10:00:00+00:00',
    ));

    return $eventId;
}
