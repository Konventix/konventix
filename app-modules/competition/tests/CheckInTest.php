<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Competition\Actions\CheckInEntrant;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Actions\UndoCheckIn;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Events\EntrantCheckedIn;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Identity\Models\User;

/**
 * Check-in (design §6, D-CMP-18, #298).
 *
 * Three things are pinned here, and they are three different claims:
 *
 * 1. **The three modes mean three different things.** `none` makes every
 *    registered entrant ready and there is nothing to do; `self_service`
 *    gives the captain a window; `desk` gives nobody but the desk. The desk
 *    may act in all three.
 * 2. **The door requirement is answered from this module's own read model.**
 *    `competition_admission_presence` is projected from Admission's public
 *    events and nothing here ever queries Admission (ADR-0004). The
 *    projection is idempotent, replayable, and short-circuits when
 *    `competition` is off for the event's brand (#298's second acceptance
 *    criterion, ADR-0003). **All three ways into that table are exercised**:
 *    the ordinary attendee (issued anonymous, then
 *    `AdmissionHolderAssigned` — the main path), the crew badge (holder named
 *    on `AdmissionIssued`, no second event) and the #292 backfill
 *    (`AdmissionOwnershipAnnounced`).
 * 3. **Readiness reaches the bracket.** A competition that checks its teams
 *    in seeds the checked-in ones only (§7.5).
 * 4. **A GDPR erasure takes the person out of the door read model** and
 *    leaves the scan standing, the way Resources does it for the same column.
 *
 * Helper names are process-global across every module's suite
 * (.ai/rules/tests.md); the ones only this file uses are prefixed
 * `checkInTest`.
 */
/**
 * @param  array<string, mixed>  $overrides
 */
function checkInTestCompetition(
    string $eventId,
    CheckInMode $mode,
    int $teamSize = 1,
    array $overrides = [],
): Competition {
    $discipline = Discipline::factory()->teamSize($teamSize)->create();

    return Competition::factory()->create(array_merge([
        'discipline_id' => $discipline->getKey(),
        'event_id' => $eventId,
        'check_in_mode' => $mode,
        'starts_at' => now()->addHours(2),
    ], $overrides));
}

/**
 * A team of the given people, entered in the competition. The first person is
 * the captain — `CreateTeam`'s rule (§5.2), which the self-service path here
 * depends on.
 *
 * @param  list<string>  $userIds
 */
function checkInTestEnter(Competition $competition, string $brandId, string $eventId, string $name, array $userIds): Entrant
{
    $team = app(CreateTeam::class)->handle($brandId, $eventId, $name, $userIds[0]);

    foreach (array_slice($userIds, 1) as $userId) {
        app(JoinTeamByCode::class)->handle($brandId, $team->refresh()->invite_code, $userId);
    }

    return app(EnterCompetition::class)->handle($competition, $team->refresh());
}

/**
 * The door sees somebody holding a **crew badge**, the shorter of the two
 * shapes Admission emits.
 *
 * `IssueCrewAdmission` is the one path that names the holder on
 * `AdmissionIssued` and emits no second event — a badge belongs to a named
 * person from the moment it exists — so this is the whole of it: the issue,
 * then `AdmissionCheckedIn` for the scan.
 *
 * **This is not the ordinary attendee's shape.** That one is
 * `checkInTestAttendeeScan()`, which is the main path §6 names: a
 * registration-derived admission is issued with `holder_user_id` null and
 * gets its holder on `AdmissionHolderAssigned`. Both are exercised here on
 * purpose — a suite that only knew this one would stay green with
 * `handleHolderAssigned()` deleted, and every ordinary ticket would lose its
 * door presence.
 *
 * Returns the admission's ULID so a test can revoke or unassign it.
 */
function checkInTestScan(string $brandId, string $eventId, string $userId, string $direction = 'in'): string
{
    $admissionId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    event(new AdmissionIssued(
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brandId,
        role: 'attendee',
        holderUserId: $userId,
        issuedAt: now()->toIso8601String(),
        buyerUserId: $userId,
    ));

    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brandId,
        eventDayId: null,
        checkpointId: null,
        direction: $direction,
        scannedAt: now()->toIso8601String(),
    ));

    return $admissionId;
}

/**
 * The door sees an **ordinary attendee**, the way it really happens — the
 * main path of design §6, in the order Admission emits it:
 *
 * 1. `AdmissionIssued` with `holderUserId: null`. Every registration-derived
 *    admission is issued anonymous (`IssueAdmissionsForRegistration`); the
 *    buyer is a different column and a group booking buys for other people.
 * 2. `AdmissionHolderAssigned`, when the ticket is claimed or an organizer
 *    assigns a name (`ClaimAdmission` / `AssignHolder`, ADR-0032). This is
 *    where `holder_user_id` comes from for nearly everybody at the door.
 * 3. `AdmissionCheckedIn`, the scan.
 *
 * Returns the admission's ULID.
 */
function checkInTestAttendeeScan(string $brandId, string $eventId, string $userId): string
{
    $admissionId = (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    event(new AdmissionIssued(
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brandId,
        role: 'attendee',
        holderUserId: null,
        issuedAt: now()->toIso8601String(),
        buyerUserId: $userId,
    ));

    event(new AdmissionHolderAssigned(
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brandId,
        holderUserId: $userId,
        viaClaimLink: true,
        assignedAt: now()->toIso8601String(),
    ));

    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId,
        registrationId: $registrationId,
        eventId: $eventId,
        brandId: $brandId,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    return $admissionId;
}

it('treats every registered entrant as ready when the mode is none, and still lets the desk tick one off', function (): void {
    // §6's first row: "`none`: every registered entrant counts as ready."
    // There is nothing for a captain to do — and the desk may act anyway,
    // because §6 gives it both directions "whatever the mode".
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::None);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Solo', [$captain]);

    expect($entrant->state)->toBe(EntrantState::Registered)
        ->and($entrant->isReadyFor(CheckInMode::None))->toBeTrue()
        // The captain has no self-service path: the mode does not allow one.
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant, $captain))
        ->toThrow(CompetitionEntryException::class);

    expect(app(CheckInEntrant::class)->handle($entrant)->state)->toBe(EntrantState::CheckedIn);
});

it('lets the captain check the team in inside the self-service window and refuses every other member', function (): void {
    // §6's second row. The window is open: the competition starts in two
    // hours and check-in opens three hours before it.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::SelfService, teamSize: 2, overrides: [
        'check_in_opens_minutes_before' => 180,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $member = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Zwei', [$captain, $member]);

    Event::fake([EntrantCheckedIn::class]);

    expect($competition->selfServiceCheckInIsOpen())->toBeTrue()
        // A roster member who is not the captain may not declare the rest of
        // the team present.
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant, $member))
        ->toThrow(CompetitionEntryException::class);

    $checked = app(CheckInEntrant::class)->handle($entrant, $captain);

    expect($checked->state)->toBe(EntrantState::CheckedIn)
        ->and($checked->checked_in_at)->not->toBeNull()
        ->and($checked->isReadyFor(CheckInMode::SelfService))->toBeTrue();

    Event::assertDispatched(EntrantCheckedIn::class, function (EntrantCheckedIn $event) use ($entrant, $captain, $member): bool {
        return $event->entrantId === (string) $entrant->getKey()
            && $event->memberUserIds === [$captain, $member]
            && $event->viaSelfService === true;
    });
});

it('refuses a self-service check-in in desk mode and lets the desk do it', function (): void {
    // §6's third row: "`desk`: only the desk checks teams in."
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Desk', [$captain]);

    expect($competition->selfServiceCheckInIsOpen())->toBeFalse()
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant, $captain))
        ->toThrow(CompetitionEntryException::class);

    // The desk, with no self-service user id, is not asked about the mode.
    expect(app(CheckInEntrant::class)->handle($entrant)->state)->toBe(EntrantState::CheckedIn);
});

it('opens the self-service window exactly on the minute it is configured for, and shuts it at start', function (): void {
    // The two edges §6 defines: `check_in_opens_minutes_before` minutes
    // before `starts_at` — inclusive — and "closing at `start`", which is the
    // organizer starting the competition, not the clock passing `starts_at`.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $startsAt = now()->addDay()->startOfHour();
    $competition = checkInTestCompetition($eventId, CheckInMode::SelfService, overrides: [
        'check_in_opens_minutes_before' => 90,
        'starts_at' => $startsAt,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Pünktlich', [$captain]);

    $opensAt = $startsAt->copy()->subMinutes(90);

    expect($competition->selfServiceCheckInOpensAt()->equalTo($opensAt))->toBeTrue();

    $this->travelTo($opensAt->copy()->subSecond());
    expect($competition->selfServiceCheckInIsOpen())->toBeFalse()
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant, $captain))
        ->toThrow(CompetitionEntryException::class);

    $this->travelTo($opensAt);
    expect($competition->selfServiceCheckInIsOpen())->toBeTrue();

    $checked = app(CheckInEntrant::class)->handle($entrant, $captain);
    expect($checked->state)->toBe(EntrantState::CheckedIn);

    // Once the competition is running the window is shut, whatever the clock
    // says — an already checked-in entrant is a no-op, so undo first.
    app(UndoCheckIn::class)->handle($checked);
    $competition->forceFill(['status' => CompetitionStatus::Running])->save();

    expect($competition->refresh()->selfServiceCheckInIsOpen())->toBeFalse()
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant->refresh(), $captain))
        ->toThrow(CompetitionEntryException::class);

    $this->travelBack();
});

it('never opens a self-service window for a competition with no start time', function (): void {
    // The window is defined relative to `starts_at`. With no start there is
    // no anchor, so it never opens — and the desk is the way in meanwhile.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::SelfService, overrides: ['starts_at' => null]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Ohne Startzeit', [$captain]);

    expect($competition->selfServiceCheckInOpensAt())->toBeNull()
        ->and($competition->selfServiceCheckInIsOpen())->toBeFalse()
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant, $captain))
        ->toThrow(CompetitionEntryException::class);

    expect(app(CheckInEntrant::class)->handle($entrant)->state)->toBe(EntrantState::CheckedIn);
});

it('refuses a check-in while a roster member has not been seen at the event door', function (): void {
    // #298's first acceptance criterion, and the whole reason
    // `competition_admission_presence` exists: the fact comes from
    // Admission's events, never from a query into Admission (ADR-0004).
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk, teamSize: 2, overrides: [
        'requires_event_check_in' => true,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $member = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Halb da', [$captain, $member]);

    expect(fn () => app(CheckInEntrant::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class);

    checkInTestScan($brand->id, $eventId, $captain);

    // One of two is not enough: §6 says *every* roster member.
    expect(AdmissionPresence::missingAtTheDoor($brand->id, $eventId, [$captain, $member]))->toBe([$member])
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class);

    checkInTestScan($brand->id, $eventId, $member);

    expect(app(CheckInEntrant::class)->handle($entrant->refresh())->state)->toBe(EntrantState::CheckedIn);
});

it('lets an ordinary attendee through the door the way Admission really emits it', function (): void {
    // **The main path of §6, end to end.** A registration-derived admission
    // is issued with `holder_user_id` NULL
    // (`IssueAdmissionsForRegistration`), gets its holder on
    // `AdmissionHolderAssigned` when it is claimed (ADR-0032), and is then
    // scanned. The test above uses the crew-badge shape, where the holder
    // arrives on `AdmissionIssued`; without this one `handleHolderAssigned()`
    // could be deleted and the suite would stay green while every ordinary
    // ticket lost its door presence.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk, teamSize: 2, overrides: [
        'requires_event_check_in' => true,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $member = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Selbst gekauft', [$captain, $member]);

    // An admission that is issued and never claimed names nobody, so its
    // holder is not at the door however often it is scanned.
    $unclaimed = (string) Str::ulid();
    event(new AdmissionIssued(
        admissionId: $unclaimed,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        role: 'attendee',
        holderUserId: null,
        issuedAt: now()->toIso8601String(),
        buyerUserId: $captain,
    ));
    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $unclaimed,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    expect(AdmissionPresence::withoutBrandScope()->whereKey($unclaimed)->first()->holder_user_id)->toBeNull()
        ->and(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$captain, $member]))->toBe([])
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class);

    checkInTestAttendeeScan($brand->id, $eventId, $captain);

    expect(AdmissionPresence::missingAtTheDoor($brand->id, $eventId, [$captain, $member]))->toBe([$member]);

    checkInTestAttendeeScan($brand->id, $eventId, $member);

    expect(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$captain, $member]))
        ->toEqualCanonicalizing([$captain, $member])
        ->and(app(CheckInEntrant::class)->handle($entrant->refresh())->state)->toBe(EntrantState::CheckedIn);
});

it('lets the #292 backfill put a pre-existing admission into the read model', function (): void {
    // `AdmissionOwnershipAnnounced` is the only way an admission that is
    // older than this read model ever enters it: its issue and its claim
    // happened before anything was listening. Without
    // `handleOwnershipAnnounced()` a brand that switched the module on would
    // see an empty table and refuse every `requires_event_check_in` team.
    //
    // It restates the holder and the state, and carries **no scan
    // timestamp** — so the backfill alone brings the row back and not the
    // door, which is exactly the recovery gap the listener's docblock names.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk, overrides: [
        'requires_event_check_in' => true,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Von früher', [$captain]);

    $admissionId = (string) Str::ulid();
    $revokedAdmission = (string) Str::ulid();

    event(new AdmissionOwnershipAnnounced(
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $captain,
        buyerUserId: $captain,
        state: 'assigned',
    ));

    // The announcement knows who holds it and nothing about a scan, so the
    // door has still not seen anybody.
    expect(AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->first()->holder_user_id)->toBe($captain)
        ->and(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$captain]))->toBe([])
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class);

    // The scan that follows lands on the row the backfill created.
    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    expect(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$captain]))->toBe([$captain])
        ->and(app(CheckInEntrant::class)->handle($entrant->refresh())->state)->toBe(EntrantState::CheckedIn);

    // The backfill walks revoked admissions too, and one of those clears the
    // row rather than restating it — a re-run after somebody's ticket was
    // cancelled must not put them back at the door.
    event(new AdmissionOwnershipAnnounced(
        admissionId: $revokedAdmission,
        registrationId: null,
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $captain,
        buyerUserId: null,
        state: 'assigned',
    ));

    expect(AdmissionPresence::withoutBrandScope()->whereKey($revokedAdmission)->exists())->toBeTrue();

    event(new AdmissionOwnershipAnnounced(
        admissionId: $revokedAdmission,
        registrationId: null,
        eventId: $eventId,
        brandId: $brand->id,
        holderUserId: $captain,
        buyerUserId: null,
        state: 'revoked',
    ));

    expect(AdmissionPresence::withoutBrandScope()->whereKey($revokedAdmission)->exists())->toBeFalse();
});

it('ignores the door entirely when the competition does not require it', function (): void {
    // The two knobs are independent: a `desk` competition without
    // `requires_event_check_in` never asks the read model at all.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Niemand gescannt', [$captain]);

    expect(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$captain]))->toBe([])
        ->and(app(CheckInEntrant::class)->handle($entrant)->state)->toBe(EntrantState::CheckedIn);
});

it('undoes a check-in, refuses to undo one that never happened, and treats a second check-in as a no-op', function (): void {
    // The desk's correction (§6). Undo puts the entrant back exactly where it
    // was, so a re-check-in runs every guard again.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Doch nicht', [$captain]);

    expect(fn () => app(UndoCheckIn::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class);

    app(CheckInEntrant::class)->handle($entrant);

    Event::fake([EntrantCheckedIn::class]);

    // A second click is not an error and must not fire a second contract
    // event.
    app(CheckInEntrant::class)->handle($entrant->refresh());
    Event::assertNotDispatched(EntrantCheckedIn::class);

    $undone = app(UndoCheckIn::class)->handle($entrant->refresh());

    expect($undone->state)->toBe(EntrantState::Registered)
        ->and($undone->checked_in_at)->toBeNull()
        ->and($undone->isReadyFor(CheckInMode::Desk))->toBeFalse();
});

it('refuses to check in a roster that is still incomplete or has left the field', function (): void {
    // Only a `registered` entrant is checked in: an incomplete roster is
    // resolved first (§5.4), and one that withdrew does not come back.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk, teamSize: 2);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Zu dritt fehlt einer', [$captain]);

    expect($entrant->state)->toBe(EntrantState::Incomplete)
        ->and(fn () => app(CheckInEntrant::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class);
});

it('seeds only checked-in entrants when the competition checks its teams in', function (): void {
    // §6 and §7.5: "Bracket generation from a competition with
    // `check_in_mode ≠ none` seeds checked-in entrants only." A no-show is
    // simply not drawn.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk);

    $entrants = [];

    foreach (['Da', 'Auch da', 'Nicht erschienen', 'Ebenfalls nicht'] as $name) {
        $entrants[$name] = checkInTestEnter(
            $competition,
            $brand->id,
            $eventId,
            $name,
            [competitionEligibleUser($eventId, $brand->id)],
        );
    }

    app(CheckInEntrant::class)->handle($entrants['Da']);
    app(CheckInEntrant::class)->handle($entrants['Auch da']);

    // Signup closes before the draw (§4).
    $competition->forceFill(['status' => CompetitionStatus::SignupClosed])->save();

    $stage = Stage::factory()->create(['competition_id' => $competition->getKey()]);

    app(GenerateBracket::class)->handle($stage);

    $seeded = MatchSlot::query()->whereNotNull('entrant_id')->pluck('entrant_id')->map(strval(...))->all();

    sort($seeded);
    $expected = [(string) $entrants['Da']->getKey(), (string) $entrants['Auch da']->getKey()];
    sort($expected);

    expect($seeded)->toBe($expected);
});

it('seeds every registered entrant when the competition checks nobody in', function (): void {
    // The other half of the same rule: with `none` there is nothing to check
    // in, so `registered` is the whole field.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::None);

    $first = checkInTestEnter($competition, $brand->id, $eventId, 'Eins', [competitionEligibleUser($eventId, $brand->id)]);
    $second = checkInTestEnter($competition, $brand->id, $eventId, 'Zwei', [competitionEligibleUser($eventId, $brand->id)]);

    // Signup closes before the draw (§4).
    $competition->forceFill(['status' => CompetitionStatus::SignupClosed])->save();

    $stage = Stage::factory()->create(['competition_id' => $competition->getKey()]);

    app(GenerateBracket::class)->handle($stage);

    $seeded = MatchSlot::query()->whereNotNull('entrant_id')->pluck('entrant_id')->map(strval(...))->all();

    sort($seeded);
    $expected = [(string) $first->getKey(), (string) $second->getKey()];
    sort($expected);

    expect($seeded)->toBe($expected);
});

it('projects door presence from Admission events and converges on one row however often they replay', function (): void {
    // ADR-0004: the fact travels on the public events and is upserted on the
    // admission's own id, so a replay in any order ends in the same row.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    $this->travelTo(now()->startOfMinute());
    $firstScan = now();

    $admissionId = checkInTestScan($brand->id, $eventId, $userId);

    // The same scan again, a minute later: the FIRST time through the door is
    // what the row keeps.
    $this->travelTo($firstScan->copy()->addMinute());
    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $admissionId,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        eventDayId: null,
        checkpointId: null,
        direction: 'in',
        scannedAt: now()->toIso8601String(),
    ));

    $rows = AdmissionPresence::withoutBrandScope()->where('brand_id', $brand->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->holder_user_id)->toBe($userId)
        ->and($rows->first()->checked_in_at->equalTo($firstScan))->toBeTrue()
        ->and(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$userId]))->toBe([$userId]);

    $this->travelBack();
});

it('ignores an out scan, drops the holder when the admission is unassigned and clears the row on a revocation', function (): void {
    // An `out` scan is somebody stepping outside; the question this table
    // answers is whether the door has seen them, not whether they are inside
    // right now — that one is Admission's own presence table, for the hub.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $stepsOut = (string) Str::ulid();
    $unassigned = (string) Str::ulid();
    $revoked = (string) Str::ulid();

    $outOnly = (string) Str::ulid();
    event(new AdmissionIssued(
        admissionId: $outOnly,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        role: 'attendee',
        holderUserId: $stepsOut,
        issuedAt: now()->toIso8601String(),
        buyerUserId: $stepsOut,
    ));
    event(new AdmissionCheckedIn(
        checkInId: (string) Str::ulid(),
        admissionId: $outOnly,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        eventDayId: null,
        checkpointId: null,
        direction: 'out',
        scannedAt: now()->toIso8601String(),
    ));

    $unassignedAdmission = checkInTestScan($brand->id, $eventId, $unassigned);
    $revokedAdmission = checkInTestScan($brand->id, $eventId, $revoked);

    event(new AdmissionHolderUnassigned(
        admissionId: $unassignedAdmission,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        previousHolderUserId: $unassigned,
        unassignedByUserId: null,
        reasonCode: 'organizer_cleared',
        unassignedAt: now()->toIso8601String(),
    ));

    event(new AdmissionRevoked(
        admissionId: $revokedAdmission,
        registrationId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        revokedByUserId: null,
        reasonCode: 'registration_cancelled',
        revokedAt: now()->toIso8601String(),
    ));

    expect(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$stepsOut, $unassigned, $revoked]))->toBe([])
        ->and(AdmissionPresence::withoutBrandScope()->whereKey($revokedAdmission)->exists())->toBeFalse()
        // The row survives an unassignment — only its holder goes, which is
        // what the roster check matches on.
        ->and(AdmissionPresence::withoutBrandScope()->whereKey($unassignedAdmission)->first()->holder_user_id)
        ->toBeNull();
});

it('projects nothing while the competition module is off for the event brand', function (): void {
    // #298's second acceptance criterion, and ADR-0003: the listener short
    // circuits on the brand the *event* names — a scan at the door carries no
    // brand in context.
    $brand = $this->withBrand();

    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    checkInTestScan($brand->id, $eventId, $userId);

    expect(AdmissionPresence::withoutBrandScope()->where('brand_id', $brand->id)->count())->toBe(0);
});

it('takes the person out of the door read model on a GDPR erasure and leaves the scan standing', function (): void {
    // `competition_admission_presence.holder_user_id` is a user ULID — the
    // one place this module records that a named person stood at a door at a
    // time — so erasure may not wait for §13's slice.
    //
    // The row stays and the person leaves it, which is the shape Admission's
    // own tombstone has and the shape Resources already uses for the same
    // column (`ResourcesGdprHandler`, #292 §10): the scan is the
    // *admission's* fact, and how many people came through the entrance must
    // not change because somebody asked to be forgotten.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $forgotten = competitionEligibleUser($eventId, $brand->id);
    $stays = competitionEligibleUser($eventId, $brand->id);

    $admissionId = checkInTestAttendeeScan($brand->id, $eventId, $forgotten);
    checkInTestAttendeeScan($brand->id, $eventId, $stays);

    expect(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$forgotten, $stays]))
        ->toEqualCanonicalizing([$forgotten, $stays]);

    (new CompetitionGdprHandler)->delete(SubjectRef::forUser($forgotten), DeletionMode::Erase);

    $row = AdmissionPresence::withoutBrandScope()->whereKey($admissionId)->first();

    expect($row)->not->toBeNull()
        ->and($row->holder_user_id)->toBeNull()
        // The scan itself is untouched: the admission was at the door.
        ->and($row->checked_in_at)->not->toBeNull()
        // And nobody else's presence moved.
        ->and(AdmissionPresence::seenAtTheDoor($brand->id, $eventId, [$forgotten, $stays]))->toBe([$stays]);
});

it('403s the check-in desk without the CheckInEntrants capability', function (): void {
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk);

    [, $admin] = competitionOperator(['platform.access-admin', 'competition.view-competitions']);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/check-in')
        ->assertForbidden();
});

it('shows the desk who the door has seen and checks a team in from there', function (): void {
    // Every row is created BEFORE the admin-host request: such a request
    // resolves no brand and BrandScope hardens brand-owned queries to empty
    // (.ai/rules/tests.md).
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = competitionConsoleEvent($brand);
    $competition = checkInTestCompetition($eventId, CheckInMode::Desk, teamSize: 2, overrides: [
        'name' => 'Main Cup',
        'requires_event_check_in' => true,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $member = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($captain, 'Kapitänin', 'kapitaenin');
    competitionParticipantName($member, 'Mitspieler', 'mitspieler');

    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Halb da', [$captain, $member]);

    checkInTestScan($brand->id, $eventId, $captain);

    [, $admin] = competitionOperator([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.check-in-entrants',
    ]);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/check-in')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/competitions/desk/CheckIn')
            ->where('competition.requiresEventCheckIn', true)
            ->where('entrants.0.members.0.displayName', 'Kapitänin')
            ->where('entrants.0.members.0.atTheDoor', true)
            ->where('entrants.0.members.1.atTheDoor', false));

    // The door still owes one player, so the desk's button refuses with a
    // field error rather than a 500 — and the error names the person, not
    // their ULID: the desk has to be able to shout a name across the hall.
    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/entrants/'.$entrant->getKey().'/check-in')
        ->assertSessionHasErrors('entrant');

    expect(session('errors')->first('entrant'))
        ->toContain('Mitspieler')
        ->not->toContain($member);

    checkInTestScan($brand->id, $eventId, $member);

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/entrants/'.$entrant->getKey().'/check-in')
        ->assertSessionHasNoErrors();

    expect(Entrant::withoutBrandScope()->whereKey($entrant->getKey())->first()->state)
        ->toBe(EntrantState::CheckedIn);

    $this->actingAs($admin)
        ->delete('http://admin.tournaments.test/admin/entrants/'.$entrant->getKey().'/check-in')
        ->assertSessionHasNoErrors();

    expect(Entrant::withoutBrandScope()->whereKey($entrant->getKey())->first()->state)
        ->toBe(EntrantState::Registered);
});

it('lets a captain check in from /my/tournaments inside the window and nobody else', function (): void {
    // The participant half of §6, on the page §10 puts it on. The brand site
    // is behind `ensure-module:competition` (ADR-0003), so the module is
    // switched on for this brand first.
    //
    // `Identity\Models\User` appears here and **only** here: `actingAs()`
    // needs a real account row. No file under `src/` imports it — names come
    // from `ParticipantProfile`, this module's own read model (§10, #297).
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = checkInTestCompetition($eventId, CheckInMode::SelfService, teamSize: 2, overrides: [
        'check_in_opens_minutes_before' => 180,
    ]);

    $captain = User::factory()->create();
    $member = User::factory()->create();

    competitionEligibleUser($eventId, $brand->id, (string) $captain->getKey());
    competitionEligibleUser($eventId, $brand->id, (string) $member->getKey());

    $entrant = checkInTestEnter($competition, $brand->id, $eventId, 'Selbst', [
        (string) $captain->getKey(),
        (string) $member->getKey(),
    ]);

    // A roster member who is not the captain gets the Action's refusal as a
    // field error, not a 500.
    $this->actingAs($member)
        ->post('/my/tournaments/entries/'.$entrant->getKey().'/check-in')
        ->assertSessionHasErrors('entrant');

    $this->actingAs($captain)
        ->post('/my/tournaments/entries/'.$entrant->getKey().'/check-in')
        ->assertSessionHasNoErrors();

    expect($entrant->refresh()->state)->toBe(EntrantState::CheckedIn);

    // A team you are not on does not exist as far as you are concerned.
    $this->actingAs(User::factory()->create())
        ->post('/my/tournaments/entries/'.$entrant->getKey().'/check-in')
        ->assertNotFound();
});
