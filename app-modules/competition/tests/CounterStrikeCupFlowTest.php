<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\ConfirmMatchResult;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\FillIncompleteEntrant;
use Konventix\Competition\Actions\FinishCompetition;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Actions\ResolveDispute;
use Konventix\Competition\Actions\ScheduleRound;
use Konventix\Competition\Actions\SeedStage;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Events\MatchDisputed;
use Konventix\Competition\Events\MatchDisputeResolved;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Events\MatchResultCorrected;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;

/**
 * Definition of done, archetype one (#32, design §13): a 2v2
 * single-elimination Counter-Strike cup, from signup to final standings.
 *
 * One incomplete team is filled from the interested list at signup close
 * (§5.4). Round one is reported by one side and confirmed by the other
 * (§8.2) in one match, and disputed by a counter-report and resolved by the
 * desk (§8.4) in the other. The final is played, then the desk corrects
 * round one's first match, which voids the final (§8.5). The final is played
 * again between the right teams, and `MatchFinished` goes out once per match
 * across all of it (D-CMP-12).
 *
 * The draw is random, so no seed is assumed: the sides are read out of each
 * match (`competitionSidesOf()`), and every expected value below is derived
 * from them.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */

/**
 * Somebody on an entrant's roster who can speak for the side.
 */
function csCupMemberOf(string $entrantId): string
{
    /** @var Entrant $entrant */
    $entrant = Entrant::query()->findOrFail($entrantId);

    return $entrant->memberUserIds()[0];
}

function csCupEntrant(string $entrantId): Entrant
{
    /** @var Entrant */
    return Entrant::query()->findOrFail($entrantId);
}

function csCupMatch(Stage $stage, int $round, int $position): CompetitionMatch
{
    return competitionMatchAt($stage, $round, $position);
}

/**
 * Side 1 reports `$scores`, side 2 confirms (§8.2).
 *
 * @param  array<int, int>  $scores  position => score
 */
function csCupReportAndConfirm(CompetitionMatch $match, array $scores): void
{
    $sides = competitionSidesOf($match);

    app(ReportMatchResult::class)->handle(
        $match,
        csCupEntrant($sides[1]),
        new MatchReportData(scores: $scores),
        csCupMemberOf($sides[1]),
    );

    app(ConfirmMatchResult::class)->handle($match->fresh(), csCupEntrant($sides[2]), csCupMemberOf($sides[2]));
}

it('runs a 2v2 cup from signup to final standings, through a fill-up, a dispute and a cascading correction', function (): void {
    $brand = $this->withBrand();
    $brandId = (string) $brand->getKey();
    $eventId = (string) Str::ulid();
    $gameId = (string) Str::ulid();

    $competition = Competition::factory()->create([
        'event_id' => $eventId,
        'name' => 'CS2 Wingman Cup',
        'slug' => 'cs2-wingman-cup',
        'discipline_id' => Discipline::factory()->videoGame($gameId)->teamSize(2)->create(['name' => 'Counter-Strike 2'])->getKey(),
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);

    $stage = Stage::factory()->format('single_elimination')->create(['competition_id' => $competition->getKey()]);

    /*
     * Signup (§5). Four teams: three complete pairs, and one captain alone.
     * A fifth person puts their name on the interested list.
     */
    $player = fn (): string => competitionEligibleUser($eventId, $brandId);

    $entrants = [];

    foreach (['Kellerkinder', 'Nachtschicht', 'Headshot Hasen'] as $name) {
        $team = app(CreateTeam::class)->handle($brandId, $eventId, $name, $player());
        app(JoinTeamByCode::class)->handle($brandId, $team->refresh()->invite_code, $player());

        $entrants[$name] = app(EnterCompetition::class)->handle($competition, $team->refresh());
    }

    $alone = app(CreateTeam::class)->handle($brandId, $eventId, 'Einzelgänger', $player());
    $short = app(EnterCompetition::class)->handle($competition, $alone->refresh());

    $interested = $player();
    competitionParticipantName($interested, 'Suche Team', 'suche-team');
    app(ExpressInterest::class)->handle($competition, $interested, 'Spiele Support');

    expect(array_map(fn (Entrant $entrant): EntrantState => $entrant->state, $entrants))
        ->each->toBe(EntrantState::Registered)
        ->and($short->state)->toBe(EntrantState::Incomplete);

    /*
     * Signup closes; the organizer fills the short roster from the list
     * (§5.4), which takes the person off it (§5.3).
     */
    app(CloseSignup::class)->handle($competition);
    app(FillIncompleteEntrant::class)->handle($short->refresh(), [$interested]);

    expect($short->refresh()->state)->toBe(EntrantState::Registered)
        ->and($short->memberUserIds())->toHaveCount(2)
        ->and(CompetitionInterest::forCompetition($brandId, (string) $competition->getKey()))->toHaveCount(0);

    /*
     * The draw (§7.5) and the start (§4). Four teams in a bracket of four:
     * two round-one matches feeding one final.
     */
    app(SeedStage::class)->handle($stage);
    app(GenerateBracket::class)->handle($stage->refresh());
    app(StartCompetition::class)->handle($competition->refresh());

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);

    $first = csCupMatch($stage, 1, 1);
    $second = csCupMatch($stage, 1, 2);
    $final = csCupMatch($stage, 2, 1);

    [$a, $b] = array_values(competitionSidesOf($first));
    [$c, $d] = array_values(competitionSidesOf($second));

    expect([$a, $b, $c, $d])->toEqualCanonicalizing(array_map(
        fn (Entrant $entrant): string => (string) $entrant->getKey(),
        [...array_values($entrants), $short],
    ));

    Event::fake([
        MatchScheduled::class,
        MatchFinished::class,
        MatchResultCorrected::class,
        MatchDisputed::class,
        MatchDisputeResolved::class,
    ]);

    /*
     * The desk times both rounds up front (D-CMP-21). Round one's two
     * matches have both sides and are announced; the final has none yet and
     * is not (design §14, "§12 reconciled").
     */
    app(ScheduleRound::class)->handle($stage->refresh(), null, 1, CarbonImmutable::parse('2026-11-21 13:00:00', 'UTC'));
    app(ScheduleRound::class)->handle($stage->refresh(), null, 2, CarbonImmutable::parse('2026-11-21 15:00:00', 'UTC'));

    Event::assertDispatchedTimes(MatchScheduled::class, 2);

    /*
     * Round one, match 1: A reports 13:7, B confirms. A goes to the final.
     */
    csCupReportAndConfirm($first->fresh(), [1 => 13, 2 => 7]);

    expect($first->fresh()->state)->toBe(MatchState::Finished)
        ->and($first->fresh()->winner_entrant_id)->toBe($a)
        ->and(competitionSidesOf($final->fresh()))->toBe([1 => $a]);

    /*
     * Round one, match 2: C reports 13:10, D counter-reports 10:13 — a
     * dispute (§8.2). The desk looks at the demo and decides for C, with the
     * note both sides are shown (§8.4).
     */
    app(ReportMatchResult::class)->handle($second->fresh(), csCupEntrant($c), new MatchReportData(scores: [1 => 13, 2 => 10]), csCupMemberOf($c));
    app(ReportMatchResult::class)->handle($second->fresh(), csCupEntrant($d), new MatchReportData(scores: [1 => 10, 2 => 13]), csCupMemberOf($d));

    expect($second->fresh()->isDisputed())->toBeTrue();

    Event::assertDispatchedTimes(MatchDisputed::class, 1);

    $desk = (string) Str::ulid();

    app(ResolveDispute::class)->handle($second->fresh(), [1 => 13, 2 => 10], $c, $desk, 'Demo geprüft: 13:10 für die erste Seite.');

    Event::assertDispatchedTimes(MatchDisputeResolved::class, 1);

    // The final now has both sides, and that is when it is announced.
    Event::assertDispatchedTimes(MatchScheduled::class, 3);
    Event::assertDispatched(MatchScheduled::class, fn (MatchScheduled $event): bool => $event->matchId === (string) $final->getKey()
        && $event->gameId === $gameId
        && $event->entrantIds === [$a, $c]
        && count($event->memberUserIds[$a]) === 2
        && count($event->memberUserIds[$c]) === 2);

    /*
     * The final: A reports 16:12 against C, C confirms.
     */
    csCupReportAndConfirm($final->fresh(), [1 => 16, 2 => 12]);

    expect($final->fresh()->winner_entrant_id)->toBe($a);

    Event::assertDispatchedTimes(MatchFinished::class, 3);

    /*
     * The correction (§8.5). Match 1 was entered the wrong way round: B won
     * 13:7. B, not A, belongs in the final, so the final's result is taken
     * away — with the organizer's note — and it is on again between B and C.
     */
    app(CorrectMatchResult::class)->handle($first->fresh(), [$a => 7, $b => 13], $b, $desk, 'Ergebnis war vertauscht eingetragen.');

    $final->refresh();

    expect($first->fresh()->winner_entrant_id)->toBe($b)
        ->and($final->state)->toBe(MatchState::Scheduled)
        ->and($final->winner_entrant_id)->toBeNull()
        ->and(competitionSidesOf($final))->toBe([1 => $b, 2 => $c])
        ->and($final->history()->pluck('kind')->contains(MatchHistoryKind::VoidedByCorrection))->toBeTrue();

    Event::assertDispatchedTimes(MatchResultCorrected::class, 1);
    // The voided final is announced again with its new sides.
    Event::assertDispatchedTimes(MatchScheduled::class, 4);
    Event::assertDispatched(MatchScheduled::class, fn (MatchScheduled $event): bool => $event->matchId === (string) $final->getKey()
        && $event->entrantIds === [$b, $c]);

    /*
     * The final is played again: C wins 16:14 this time. It is a new result
     * for a match GameOps already tore down once, so no second MatchFinished
     * goes out (D-CMP-12).
     */
    $sides = competitionSidesOf($final);

    app(ReportMatchResult::class)->handle($final->fresh(), csCupEntrant($sides[2]), new MatchReportData(scores: [1 => 14, 2 => 16]), csCupMemberOf($sides[2]));
    app(ConfirmMatchResult::class)->handle($final->fresh(), csCupEntrant($sides[1]), csCupMemberOf($sides[1]));

    expect($final->fresh()->state)->toBe(MatchState::Finished)
        ->and($final->fresh()->winner_entrant_id)->toBe($c);

    Event::assertDispatchedTimes(MatchFinished::class, 3);

    app(FinishCompetition::class)->handle($competition->refresh());

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Finished);

    /*
     * Final standings, by hand: the final's winner C is 1st and its loser B
     * 2nd; A (lost match 1 after the correction) and D (lost match 2) went
     * out in round one, one tier, so both are 3rd and nobody is 4th.
     */
    expect(Standing::query()->where('stage_id', $stage->getKey())->pluck('placement', 'entrant_id')->all())
        ->toEqual([$c => 1, $b => 2, $a => 3, $d => 3]);

    // Every team keeps its name through all of it (§10's pages read these).
    expect(CompetitionTeam::query()->where('event_id', $eventId)->pluck('name')->sort()->values()->all())
        ->toBe(['Einzelgänger', 'Headshot Hasen', 'Kellerkinder', 'Nachtschicht']);
});
