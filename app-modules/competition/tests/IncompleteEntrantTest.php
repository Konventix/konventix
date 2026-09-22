<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\DeclineIncompleteEntrant;
use Konventix\Competition\Actions\DisqualifyEntrant;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\FillIncompleteEntrant;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Actions\LeaveTeam;
use Konventix\Competition\Actions\ScheduleMatch;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Actions\WithdrawEntrant;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Events\EntrantWithdrawn;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Events\SignupClosed;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * Incomplete rosters (design §5.4) and the two ways an entrant leaves the
 * field (§5.5).
 *
 * The headline case is #297's first acceptance criterion and the reason the
 * state exists at all: a 5v5 team of three registers, sits `incomplete`
 * holding its slot, and is filled from the interested list when signup
 * closes.
 *
 * **Resolution happens after `SignupClosed` fires, not before it.** §5.4 says
 * "on `closeSignup` the console shows the organizer one prompt per incomplete
 * entrant with two choices" — a human decision about named people, not
 * something an Action can take on its own. So `CloseSignup` stays exactly as
 * Task 1 built it, `SignupClosed.entrantCount` counts incomplete rosters
 * because they still hold slots, and `start` is the gate that cannot be
 * clicked past. These tests pin that order.
 *
 * Helper names are process-global (.ai/rules/tests.md); the ones only this
 * file uses are prefixed `incompleteTest`.
 */
function incompleteTestFiveOnFive(string $brandId, string $eventId): Competition
{
    $discipline = Discipline::factory()->teamSize(5)->create();

    return Competition::factory()->create([
        'discipline_id' => $discipline->getKey(),
        'event_id' => $eventId,
    ]);
}

/**
 * A one-player contest, for the §5.5 cases that have to reach `Running` —
 * `start` refuses while anything is `incomplete`, so a field of solo entrants
 * is the shortest honest way there.
 */
function incompleteTestSolo(string $brandId, string $eventId): Competition
{
    $discipline = Discipline::factory()->teamSize(1)->create();

    return Competition::factory()->create([
        'discipline_id' => $discipline->getKey(),
        'event_id' => $eventId,
    ]);
}

/**
 * @param  list<string>  $userIds
 */
function incompleteTestTeamOf(string $brandId, string $eventId, string $name, array $userIds): CompetitionTeam
{
    $team = app(CreateTeam::class)->handle($brandId, $eventId, $name, $userIds[0]);

    foreach (array_slice($userIds, 1) as $userId) {
        app(JoinTeamByCode::class)->handle($brandId, $team->refresh()->invite_code, $userId);
    }

    return $team->refresh();
}

it('registers a 5v5 team of three as incomplete and fills it from the interested list at close', function (): void {
    // #297 acceptance criterion 1, end to end. §5.4: the short roster enters,
    // holds its slot, and the organizer resolves it once signup is shut.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $three = [
        competitionEligibleUser($eventId, $brand->id),
        competitionEligibleUser($eventId, $brand->id),
        competitionEligibleUser($eventId, $brand->id),
    ];

    $team = incompleteTestTeamOf($brand->id, $eventId, 'Zu dritt', $three);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    expect($entrant->state)->toBe(EntrantState::Incomplete)
        // It holds a slot: that is what makes the fill-up possible at all.
        ->and(Entrant::countSlotHolders($brand->id, (string) $competition->getKey()))->toBe(1);

    // Two more people put their names down on the public list (§5.3).
    $fourth = competitionEligibleUser($eventId, $brand->id);
    $fifth = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($fourth, 'Vierter', 'vierter');
    competitionParticipantName($fifth, 'Fünfter', 'fuenfter');
    app(ExpressInterest::class)->handle($competition, $fourth, 'Spiele AWP');
    app(ExpressInterest::class)->handle($competition, $fifth, null);

    app(CloseSignup::class)->handle($competition);
    expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);

    // The interested list survives the close, because §5.4's fill-up reads it.
    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(2);

    app(FillIncompleteEntrant::class)->handle($entrant, [$fourth, $fifth]);

    expect($entrant->refresh()->state)->toBe(EntrantState::Registered)
        ->and($entrant->memberUserIds())->toBe([...$three, $fourth, $fifth])
        // Both are now on a roster, so neither is still looking (§5.3).
        ->and(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(0);
});

it('counts an incomplete roster in SignupClosed, because it still holds a slot', function (): void {
    // §5.4 and Task 1's own note on the event: a count that hid them would
    // understate the field the organizer is about to resolve.
    Event::fake([SignupClosed::class]);

    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $short = incompleteTestTeamOf($brand->id, $eventId, 'Kurz', [competitionEligibleUser($eventId, $brand->id)]);
    app(EnterCompetition::class)->handle($competition, $short);

    app(CloseSignup::class)->handle($competition);

    Event::assertDispatched(SignupClosed::class, fn (SignupClosed $event): bool => $event->entrantCount === 1);
});

it('moves an entry between incomplete and registered as the roster changes', function (): void {
    // §5.4: "a later join or leave moves it between the two automatically".
    // The state is derived; no caller has to remember to set it.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $discipline = Discipline::factory()->teamSize(2)->create();
    $competition = Competition::factory()->create([
        'discipline_id' => $discipline->getKey(),
        'event_id' => $eventId,
    ]);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $second = competitionEligibleUser($eventId, $brand->id);

    $team = incompleteTestTeamOf($brand->id, $eventId, 'Duo', [$captain]);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    expect($entrant->state)->toBe(EntrantState::Incomplete);

    app(JoinTeamByCode::class)->handle($brand->id, $team->refresh()->invite_code, $second);
    expect($entrant->refresh()->state)->toBe(EntrantState::Registered);

    app(LeaveTeam::class)->handle($team, $second);
    expect($entrant->refresh()->state)->toBe(EntrantState::Incomplete);
});

it('declines an incomplete entry, names the reason and frees the slot', function (): void {
    // §5.4's other choice: withdrawn with `withdrawal_reason =
    // incomplete_roster`, the members told, the slot freed.
    Event::fake([EntrantWithdrawn::class]);

    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $team = incompleteTestTeamOf($brand->id, $eventId, 'Bleibt leer', [$captain]);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    app(CloseSignup::class)->handle($competition);
    app(DeclineIncompleteEntrant::class)->handle($entrant);

    expect($entrant->refresh()->state)->toBe(EntrantState::Withdrawn)
        ->and($entrant->withdrawal_reason)->toBe(WithdrawalReason::IncompleteRoster)
        ->and(Entrant::countSlotHolders($brand->id, (string) $competition->getKey()))->toBe(0);

    Event::assertDispatched(EntrantWithdrawn::class, function (EntrantWithdrawn $event) use ($captain): bool {
        return $event->reason === 'incomplete_roster'
            && $event->finalState === 'withdrawn'
            && $event->memberUserIds === [$captain];
    });
});

it('refuses to fill or decline anything that is not incomplete', function (): void {
    // Both prompts belong to one state. Filling a full roster would put a
    // sixth player in a 5v5; declining a registered team would be a
    // withdrawal wearing the wrong reason.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $five = [];
    for ($i = 0; $i < 5; $i++) {
        $five[] = competitionEligibleUser($eventId, $brand->id);
    }

    $team = incompleteTestTeamOf($brand->id, $eventId, 'Vollzählig', $five);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    expect($entrant->state)->toBe(EntrantState::Registered)
        ->and(fn () => app(DeclineIncompleteEntrant::class)->handle($entrant))
        ->toThrow(CompetitionEntryException::class)
        ->and(fn () => app(FillIncompleteEntrant::class)->handle($entrant, [competitionEligibleUser($eventId, $brand->id)]))
        ->toThrow(CompetitionEntryException::class);
});

it('refuses to start while an incomplete roster is left, and starts once it is resolved', function (): void {
    // §4 and §5.4: `start` refuses while any entrant is incomplete, so the
    // organizer cannot click past the prompt. Declining is a resolution too.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $team = incompleteTestTeamOf($brand->id, $eventId, 'Kurz', [competitionEligibleUser($eventId, $brand->id)]);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    Stage::factory()->create(['competition_id' => $competition->getKey(), 'generated_at' => now()]);
    app(CloseSignup::class)->handle($competition);

    expect(fn () => app(StartCompetition::class)->handle($competition->refresh()))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    app(DeclineIncompleteEntrant::class)->handle($entrant);

    expect(app(StartCompetition::class)->handle($competition->refresh())->status)
        ->toBe(CompetitionStatus::Running);
});

it('refuses a fill-up of somebody who holds no confirmed registration', function (): void {
    // An organizer power is a shortcut past the invite *code*, never past the
    // §5.1 gate (D-CMP-05).
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $team = incompleteTestTeamOf($brand->id, $eventId, 'Kurz', [competitionEligibleUser($eventId, $brand->id)]);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    app(FillIncompleteEntrant::class)->handle($entrant, [(string) Str::ulid()]);
})->throws(CompetitionEntryException::class);

it('lets a captain withdraw before Running and refuses once the tournament has started', function (): void {
    // §5.5: "a captain may withdraw the team until `Running`". The reach ends
    // where other people's scheduled evening begins.
    //
    // The first round's version of this test never reached `Running` at all,
    // so `WithdrawEntrant::guardBeforeRunning()` would have survived its own
    // deletion. This one starts the tournament.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestSolo($brand->id, $eventId);

    $pullsOut = incompleteTestTeamOf($brand->id, $eventId, 'Zieht zurück', [
        competitionEligibleUser($eventId, $brand->id),
    ]);
    $stays = incompleteTestTeamOf($brand->id, $eventId, 'Bleibt', [
        competitionEligibleUser($eventId, $brand->id),
    ]);

    $early = app(EnterCompetition::class)->handle($competition, $pullsOut);
    $late = app(EnterCompetition::class)->handle($competition, $stays);

    // Before the bracket is drawn, the captain's own decision stands.
    app(WithdrawEntrant::class)->handle($early, WithdrawalReason::CaptainWithdrew);

    expect($early->refresh()->state)->toBe(EntrantState::Withdrawn)
        ->and($early->withdrawal_reason)->toBe(WithdrawalReason::CaptainWithdrew);

    // Withdrawing twice is refused rather than silently recorded again.
    expect(fn () => app(WithdrawEntrant::class)->handle($early, WithdrawalReason::OrganizerWithdrew))
        ->toThrow(CompetitionEntryException::class);

    Stage::factory()->create(['competition_id' => $competition->getKey(), 'generated_at' => now()]);
    app(CloseSignup::class)->handle($competition);
    app(StartCompetition::class)->handle($competition->refresh());

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);

    // Now the captain cannot: matches are scheduled against this team.
    expect(fn () => app(WithdrawEntrant::class)->handle($late, WithdrawalReason::CaptainWithdrew))
        ->toThrow(CompetitionEntryException::class);

    expect($late->refresh()->state)->toBe(EntrantState::Registered);
});

it('lets the organizer withdraw a team after the tournament has started', function (): void {
    // §5.5's other half: "the organizer may withdraw or disqualify at any
    // time (`ManageEntrants`)". A team that has gone home is a fact the desk
    // must be able to record whatever the clock says — and unlike a
    // disqualification it does not forfeit the pending matches, because a
    // withdrawal is usually a round being redrawn by hand.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestSolo($brand->id, $eventId);

    $team = incompleteTestTeamOf($brand->id, $eventId, 'Fährt heim', [
        competitionEligibleUser($eventId, $brand->id),
    ]);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    Stage::factory()->create(['competition_id' => $competition->getKey(), 'generated_at' => now()]);
    app(CloseSignup::class)->handle($competition);
    app(StartCompetition::class)->handle($competition->refresh());

    $match = CompetitionMatch::factory()->create(['competition_id' => $competition->getKey()]);
    $slot = new MatchSlot([
        'competition_match_id' => $match->getKey(),
        'entrant_id' => $entrant->getKey(),
        'position' => 1,
    ]);
    $slot->forceFill(['brand_id' => $match->brand_id])->save();

    app(WithdrawEntrant::class)->handle($entrant, WithdrawalReason::OrganizerWithdrew);

    expect($entrant->refresh()->state)->toBe(EntrantState::Withdrawn)
        ->and($entrant->withdrawal_reason)->toBe(WithdrawalReason::OrganizerWithdrew)
        // Not forfeited: that is `DisqualifyEntrant`'s doing, and the
        // difference between the two is the whole reason they are separate.
        ->and($match->refresh()->state)->toBe(MatchState::Pending);
});

it('forfeits a disqualified entrant pending matches so the bracket advances normally', function (): void {
    // §5.5: "each recorded as a forfeit result through RecordMatchResult, so
    // the bracket advances the normal way". No second progression mechanism
    // for the standings calculators to miss.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $loser = incompleteTestTeamOf($brand->id, $eventId, 'Fliegt raus', [competitionEligibleUser($eventId, $brand->id)]);
    $winner = incompleteTestTeamOf($brand->id, $eventId, 'Bleibt drin', [competitionEligibleUser($eventId, $brand->id)]);

    $loserEntrant = app(EnterCompetition::class)->handle($competition, $loser);
    $winnerEntrant = app(EnterCompetition::class)->handle($competition, $winner);

    $match = CompetitionMatch::factory()->create(['competition_id' => $competition->getKey()]);

    foreach ([[1, $loserEntrant], [2, $winnerEntrant]] as [$position, $entrant]) {
        $slot = new MatchSlot([
            'competition_match_id' => $match->getKey(),
            'entrant_id' => $entrant->getKey(),
            'position' => $position,
        ]);
        $slot->forceFill(['brand_id' => $match->brand_id])->save();
    }

    $forfeited = app(DisqualifyEntrant::class)->handle($loserEntrant);

    expect($forfeited)->toBe([(string) $match->getKey()])
        ->and($loserEntrant->refresh()->state)->toBe(EntrantState::Disqualified)
        ->and($loserEntrant->withdrawal_reason)->toBe(WithdrawalReason::Disqualified)
        ->and($match->refresh()->state)->toBe(MatchState::Finished)
        ->and($match->winner_entrant_id)->toBe((string) $winnerEntrant->getKey());
});

it('carries each side roster on MatchScheduled', function (): void {
    // #297: GameOps needs it from 0.10 — a server is configured with players,
    // and resolving entrant ids would mean reading this module, which is the
    // direction the dependency must never run. ULIDs only (ADR-0004).
    Event::fake([MatchScheduled::class]);

    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = incompleteTestFiveOnFive($brand->id, $eventId);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $team = incompleteTestTeamOf($brand->id, $eventId, 'Eine Seite', [$captain]);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    $match = CompetitionMatch::factory()->create(['competition_id' => $competition->getKey()]);
    $slot = new MatchSlot([
        'competition_match_id' => $match->getKey(),
        'entrant_id' => $entrant->getKey(),
        'position' => 1,
    ]);
    $slot->forceFill(['brand_id' => $match->brand_id])->save();

    app(ScheduleMatch::class)->handle($match, now()->addHour());

    Event::assertDispatched(MatchScheduled::class, function (MatchScheduled $event) use ($entrant, $captain): bool {
        return $event->memberUserIds === [(string) $entrant->getKey() => [$captain]];
    });
});
