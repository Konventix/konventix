<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The §5.1 gate's ticket leg (design §5.1, addendum of 2026-09-23, #330): a
 * person who holds a non-revoked admission in their own name for the event
 * may create a team, enter, join and register interest without a
 * registration of their own, and the console's person search finds them.
 *
 * The fact comes from Competition's own door-presence projection
 * (`competition_admission_presence`), fed only by Admission's public events,
 * which is how every test here produces it. Nothing reads Admission's tables
 * (ADR-0004).
 *
 * Two traps shape the file:
 *
 * 1. **The module gate.** `ProjectAdmissionPresence` short-circuits while
 *    `competition` is off for the event's brand, so a test that forgot
 *    `activateModule()` writes no row and passes every *refusal* for the
 *    wrong reason. Every test that expects a row activates the module, and
 *    every refusal is asked of a person who was eligible a moment earlier in
 *    the same test (`EligibilityWithdrawalTest`'s pattern).
 * 2. **One definition of the gate.** The Actions ask
 *    `EligibleRegistration::userMayEnter()` and nothing else; the refusals
 *    below come back as `noConfirmedRegistration`, pinned by the `D-CMP-05`
 *    tag its message carries (and keeps through #330's copy change).
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */

/**
 * The tag only `CompetitionEntryException::noConfirmedRegistration()`
 * carries among that class's refusals, before and after #330's copy change.
 */
function claimedTicketTestGateRefusal(): string
{
    return 'D-CMP-05';
}

/**
 * An admission issued for the event, the way Admission emits it. An ordinary
 * ticket from a (group) booking is issued with no holder and the buyer in its
 * own column (`IssueAdmissionsForRegistration`); a crew badge names its holder
 * here (`IssueCrewAdmission`). Returns the admission's fresh ULID.
 */
function claimedTicketTestIssue(
    string $brandId,
    string $eventId,
    ?string $holderUserId = null,
    ?string $buyerUserId = null,
    string $role = 'attendee',
): string {
    $admissionId = (string) Str::ulid();

    event(new AdmissionIssued(
        admissionId: $admissionId,
        registrationId: $role === 'crew' ? null : (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        role: $role,
        holderUserId: $holderUserId,
        issuedAt: now()->toIso8601String(),
        buyerUserId: $buyerUserId,
    ));

    return $admissionId;
}

/** The ticket is claimed through its link (`ClaimAdmission`, ADR-0032). */
function claimedTicketTestClaim(string $brandId, string $eventId, string $admissionId, string $holderUserId): void
{
    event(new AdmissionHolderAssigned(
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        holderUserId: $holderUserId,
        viaClaimLink: true,
        assignedAt: now()->toIso8601String(),
    ));
}

/** The ticket is revoked, e.g. because the booking behind it was refunded. */
function claimedTicketTestRevoke(string $brandId, string $eventId, string $admissionId): void
{
    event(new AdmissionRevoked(
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        revokedByUserId: null,
        reasonCode: 'registration_cancelled',
        revokedAt: now()->toIso8601String(),
    ));
}

/** An organizer takes the holder's name off the ticket; the ticket stands. */
function claimedTicketTestUnassign(string $brandId, string $eventId, string $admissionId, string $previousHolderUserId): void
{
    event(new AdmissionHolderUnassigned(
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        previousHolderUserId: $previousHolderUserId,
        unassignedByUserId: null,
        reasonCode: 'organizer_cleared',
        unassignedAt: now()->toIso8601String(),
    ));
}

/**
 * A holder with a claimed ticket and no registration of their own: an
 * anonymous issue bought by somebody else, then the claim. Returns the
 * admission's ULID.
 */
function claimedTicketTestHolder(string $brandId, string $eventId, string $holderUserId): string
{
    $admissionId = claimedTicketTestIssue($brandId, $eventId, holderUserId: null, buyerUserId: (string) Str::ulid());
    claimedTicketTestClaim($brandId, $eventId, $admissionId, $holderUserId);

    return $admissionId;
}

/**
 * A competition at the event whose discipline wants two players, so one
 * person enters as `incomplete` (§5.4) and a second can still join.
 */
function claimedTicketTestCompetition(string $eventId, string $slug = 'main-cup'): Competition
{
    return Competition::factory()->forEvent($eventId)->create([
        'slug' => $slug,
        'discipline_id' => Discipline::factory()->teamSize(2)->create()->getKey(),
    ]);
}

it('lets the holder of a claimed ticket create a team, enter, join by code and register interest without a registration of their own', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();

    $competition = claimedTicketTestCompetition($eventId);
    $secondCompetition = claimedTicketTestCompetition($eventId, 'side-cup');

    $buyer = (string) Str::ulid();
    $holder = (string) Str::ulid();
    $joiner = (string) Str::ulid();
    $interested = (string) Str::ulid();

    // Issued from somebody else's group booking, nobody named yet. This is
    // the non-vacuous half: an issued, unclaimed ticket names nobody, so it
    // opens the gate for nobody — not the buyer (who has no registration row
    // here either) and not the eventual holder.
    $admissionId = claimedTicketTestIssue($brand->id, $eventId, holderUserId: null, buyerUserId: $buyer);

    expect(AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->exists())->toBeTrue()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $buyer))->toBeFalse();

    claimedTicketTestClaim($brand->id, $eventId, $admissionId, $holder);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeTrue();

    // Create a team with the holder as captain, and enter it.
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Tickethalter', $holder);
    expect($team->captain()?->user_id)->toBe($holder);

    // One member of a team of two: `incomplete` (§5.4, 1 < 2).
    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$holder]);
    expect($entrant->state)->toBe(EntrantState::Incomplete);

    // A second holder joins another team by its code. The team is a
    // registrant's, so only the joiner's leg is the ticket.
    claimedTicketTestHolder($brand->id, $eventId, $joiner);
    $registrant = competitionEligibleUser($eventId, $brand->id);
    $otherTeam = app(CreateTeam::class)->handle($brand->id, $eventId, 'Kellerkinder', $registrant);

    app(JoinTeamByCode::class)->handle($brand->id, (string) $otherTeam->refresh()->invite_code, $joiner);

    expect($otherTeam->refresh()->memberUserIds())->toEqualCanonicalizing([$registrant, $joiner]);

    // A third holder registers interest on the second competition.
    claimedTicketTestHolder($brand->id, $eventId, $interested);
    $interest = app(ExpressInterest::class)->handle($secondCompetition, $interested, 'Suche Team');

    expect($interest->user_id)->toBe($interested);

    // None of the three got in through the registration leg: the read model
    // of confirmed registrations has no row for any of them.
    expect(EligibleRegistration::withoutBrandScope()->whereIn('user_id', [$holder, $joiner, $interested])->exists())->toBeFalse();
});

it('finds a ticket holder in the console\'s person search', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();

    $registrant = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($registrant, 'Tina Registrant', 'tina-r');

    // A registrant whose name does not match: the name filter must still
    // apply to the registration leg. Were the `orWhereIn` to escape the
    // closure, the query would read `reg OR (ticket AND name)` and return her.
    $other = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($other, 'Ursula Other', 'ursula');

    // A person who matches the name and holds nothing: never offered.
    $stranger = (string) Str::ulid();
    competitionParticipantName($stranger, 'Tina Stranger', 'tina-s');

    $holder = (string) Str::ulid();
    competitionParticipantName($holder, 'Tina Holder', 'tina-h');

    $admissionId = claimedTicketTestIssue($brand->id, $eventId, holderUserId: null, buyerUserId: (string) Str::ulid());

    // Unclaimed: the holder is nobody yet, so only the registrant matches.
    expect(ParticipantProfile::searchEligible($brand->id, $eventId, 'Tina')->pluck('user_id')->all())->toBe([$registrant]);

    claimedTicketTestClaim($brand->id, $eventId, $admissionId, $holder);

    // Ordered by display_name: "Tina Holder" < "Tina Registrant", because the
    // first difference is at character 6, 'H' (0x48) < 'R' (0x52). "Tina
    // Stranger" has no entitlement and "Ursula Other" does not match.
    expect(ParticipantProfile::searchEligible($brand->id, $eventId, 'Tina')->pluck('user_id')->all())->toBe([$holder, $registrant]);
});

it('closes the gate when the holder is removed, and leaves the roster alone', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();

    $competition = claimedTicketTestCompetition($eventId);
    $secondCompetition = claimedTicketTestCompetition($eventId, 'side-cup');

    $captain = competitionEligibleUser($eventId, $brand->id);
    $otherTeam = app(CreateTeam::class)->handle($brand->id, $eventId, 'Kellerkinder', $captain);

    $holder = (string) Str::ulid();
    $admissionId = claimedTicketTestHolder($brand->id, $eventId, $holder);

    // Eligible a moment ago: they found a team and entered it.
    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeTrue();
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Tickethalter', $holder);
    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$holder]);
    expect($entrant->state)->toBe(EntrantState::Incomplete);

    claimedTicketTestUnassign($brand->id, $eventId, $admissionId, $holder);

    // The row stands with no holder (§6), and the gate is shut.
    expect(AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->value('holder_user_id'))->toBeNull()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse();

    expect(fn () => app(CreateTeam::class)->handle($brand->id, $eventId, 'Noch ein Team', $holder))
        ->toThrow(CompetitionEntryException::class, claimedTicketTestGateRefusal());
    expect(fn () => app(JoinTeamByCode::class)->handle($brand->id, (string) $otherTeam->refresh()->invite_code, $holder))
        ->toThrow(CompetitionEntryException::class, claimedTicketTestGateRefusal());
    expect(fn () => app(ExpressInterest::class)->handle($secondCompetition, $holder, 'Suche Team'))
        ->toThrow(CompetitionEntryException::class, claimedTicketTestGateRefusal());

    // Not retroactive (§5.1 addendum, point 4): their roster and their entry
    // are exactly as they were, and none of the refused writes landed.
    expect(CompetitionTeam::query()->whereKey($team->id)->firstOrFail()->memberUserIds())->toBe([$holder])
        ->and($entrant->refresh()->state)->toBe(EntrantState::Incomplete)
        ->and($otherTeam->refresh()->memberUserIds())->toBe([$captain])
        ->and(CompetitionInterest::withoutBrandScope()->where('user_id', $holder)->exists())->toBeFalse();
});

it('closes the gate when the ticket is revoked', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();

    $competition = claimedTicketTestCompetition($eventId);
    $secondCompetition = claimedTicketTestCompetition($eventId, 'side-cup');

    $captain = competitionEligibleUser($eventId, $brand->id);
    $otherTeam = app(CreateTeam::class)->handle($brand->id, $eventId, 'Kellerkinder', $captain);

    $holder = (string) Str::ulid();
    $admissionId = claimedTicketTestHolder($brand->id, $eventId, $holder);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeTrue();
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Tickethalter', $holder);
    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$holder]);

    claimedTicketTestRevoke($brand->id, $eventId, $admissionId);

    // A revoked admission admits nobody: the row is gone (§6).
    expect(AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->exists())->toBeFalse()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse();

    expect(fn () => app(CreateTeam::class)->handle($brand->id, $eventId, 'Noch ein Team', $holder))
        ->toThrow(CompetitionEntryException::class, claimedTicketTestGateRefusal());
    expect(fn () => app(JoinTeamByCode::class)->handle($brand->id, (string) $otherTeam->refresh()->invite_code, $holder))
        ->toThrow(CompetitionEntryException::class, claimedTicketTestGateRefusal());
    expect(fn () => app(ExpressInterest::class)->handle($secondCompetition, $holder, 'Suche Team'))
        ->toThrow(CompetitionEntryException::class, claimedTicketTestGateRefusal());

    expect(CompetitionTeam::query()->whereKey($team->id)->firstOrFail()->memberUserIds())->toBe([$holder])
        ->and($entrant->refresh()->state)->toBe(EntrantState::Incomplete);
});

it('keeps a holder with their own confirmed registration eligible after the ticket is revoked', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();

    $holder = competitionEligibleUser($eventId, $brand->id);
    $admissionId = claimedTicketTestHolder($brand->id, $eventId, $holder);

    claimedTicketTestRevoke($brand->id, $eventId, $admissionId);

    // The ticket leg is gone; the registration leg still stands.
    expect(AdmissionPresence::holdsAdmission($brand->id, $eventId, $holder))->toBeFalse()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeTrue();
});

it('does not open the gate for a ticket at another event or another brand', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $otherBrand = Brand::factory()->create();
    // Active on the second brand too, so its row really is written and the
    // refusal below is the lookup's brand, not the module gate.
    $this->activateModule('competition', $otherBrand);
    $this->withBrand($brand);

    $eventA = (string) Str::ulid();
    $eventB = (string) Str::ulid();
    $holder = (string) Str::ulid();

    // A claimed ticket at event B of this brand.
    claimedTicketTestHolder($brand->id, $eventB, $holder);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventB, $holder))->toBeTrue()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventA, $holder))->toBeFalse();

    // A claimed ticket at the same event id under the second brand. The
    // lookup takes the brand explicitly, so it opens that brand's gate only.
    claimedTicketTestHolder($otherBrand->id, $eventA, $holder);

    expect(EligibleRegistration::userMayEnter($otherBrand->id, $eventA, $holder))->toBeTrue()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventA, $holder))->toBeFalse();
});

it('lets a crew badge holder in', function (): void {
    // §5.1 addendum, point 5: an admission is an admission, whatever its role.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $crew = (string) Str::ulid();

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $crew))->toBeFalse();

    claimedTicketTestIssue($brand->id, $eventId, holderUserId: $crew, role: 'crew');

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $crew))->toBeTrue();
});

it('backfills a ticket claimed before the deploy through the ownership announcement', function (): void {
    // #330's acceptance criterion 3 at the listener level: the only thing
    // Competition ever hears of such a ticket is `admission:announce-ownership`.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $holder = (string) Str::ulid();
    $admissionId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse();

    event(new AdmissionOwnershipAnnounced(
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $holder,
        buyerUserId: (string) Str::ulid(),
        state: 'assigned',
    ));

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeTrue();

    // A later run finds it revoked: the announcement deletes the row.
    event(new AdmissionOwnershipAnnounced(
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $holder,
        buyerUserId: null,
        state: 'revoked',
    ));

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse();
});

it('does not see a ticket claimed while the module was off, until the announcement runs after enabling', function (): void {
    // §5.1 addendum, point 6, recorded as behaviour and not as a bug: the
    // ticket leg inherits the presence listeners' module gate (#298).
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $holder = (string) Str::ulid();

    $admissionId = claimedTicketTestHolder($brand->id, $eventId, $holder);

    expect(AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->exists())->toBeFalse()
        ->and(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse();

    $this->activateModule('competition', $brand);

    // Enabling alone changes nothing: the claim was never heard.
    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeFalse();

    event(new AdmissionOwnershipAnnounced(
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $holder,
        buyerUserId: null,
        state: 'assigned',
    ));

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $holder))->toBeTrue();
});

it('marks a holder eligible on the competition page', function (): void {
    // The one read-only call site the Action tests do not reach:
    // `TournamentPages::competitionViewer()`.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: 'sxlan-27',
        name: 'SXLan 27',
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));
    // Created events are drafts, and a draft event's tournament pages 404.
    event(new EventPublished(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        publishedAt: '2026-10-01T10:00:00+00:00',
    ));

    $competition = claimedTicketTestCompetition($eventId);
    $url = '/events/sxlan-27/tournaments/main-cup';

    $holder = User::factory()->create();
    $holderId = (string) $holder->getKey();
    $admissionId = claimedTicketTestIssue($brand->id, $eventId, holderUserId: null, buyerUserId: (string) Str::ulid());

    // Unclaimed: not eligible, nothing to enter.
    $this->actingAs($holder)
        ->get($url)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.eligible', false)
            ->where('page.viewer.enterableTeams', []));

    $this->app->forgetScopedInstances();
    $this->withBrand($brand);

    claimedTicketTestClaim($brand->id, $eventId, $admissionId, $holderId);
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Tickethalter', $holderId);

    // Claimed and captaining a team that has not entered: eligible, and the
    // team is offered with its one member.
    $this->actingAs($holder)
        ->get($url)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.eligible', true)
            ->where('page.viewer.enterableTeams.0.id', $team->id)
            ->where('page.viewer.enterableTeams.0.members', 1));

    expect($competition->refresh()->entrants()->exists())->toBeFalse();
});
