<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Actions\ScheduleMatch;
use Konventix\Competition\Actions\ScheduleRound;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Models\CompetitionMatch;

/**
 * `MatchScheduled` goes out when a match has its time **and** its sides
 * (ADR-0012, design §12, §14 "§12 reconciled").
 *
 * The desk times whole rounds before the previous round is decided, so a
 * match is often timed while a slot still waits for its side. GameOps (0.10)
 * provisions a server on this event and configures it with the rosters in
 * `memberUserIds`, so an announcement with an empty side would be a server
 * nobody can use. The match keeps its time and its history row, and the
 * announcement waits for the last side to arrive.
 *
 * The bracket below is `SingleEliminationGenerator`'s four-entrant draw:
 * matches 1 and 2 of round one feed slots 1 and 2 of the final. The sides are
 * read out rather than assumed (`competitionSidesOf()`), since which seed the
 * generator puts where is `BracketMathematicsTest`'s subject.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function announcementTestPlay(CompetitionMatch $match): string
{
    $sides = competitionSidesOf($match);
    $winnerId = $sides[1];

    app(RecordMatchResult::class)->handle($match, [$sides[1] => 2, $sides[2] => 0], $winnerId);
    app(AdvanceBracket::class)->handle($match->fresh());

    return $winnerId;
}

it('holds the announcement of a timed match until its last side arrives, then makes it once', function (): void {
    $this->withBrand();

    [$competition, , $members] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    $first = competitionMatchAt($stage, 1, 1);
    $second = competitionMatchAt($stage, 1, 2);
    $final = competitionMatchAt($stage, 2, 1);

    Event::fake([MatchScheduled::class]);

    // The desk times the final while round one is still to be played.
    app(ScheduleMatch::class)->handle($final, CarbonImmutable::parse('2026-11-21 15:00:00', 'UTC'));

    $final->refresh();

    expect($final->state)->toBe(MatchState::Scheduled)
        ->and($final->scheduled_at?->toIso8601String())->toBe('2026-11-21T15:00:00+00:00')
        ->and($final->history()->pluck('kind')->map(fn (MatchHistoryKind $kind): string => $kind->value)->all())->toBe(['scheduled']);

    Event::assertNotDispatched(MatchScheduled::class);

    // Round one, match 1: the final has one side of two. Still nothing.
    $a = announcementTestPlay($first);

    Event::assertNotDispatched(MatchScheduled::class);

    // Match 2: the final's second slot fills, and the match is on.
    $c = announcementTestPlay($second);

    Event::assertDispatchedTimes(MatchScheduled::class, 1);
    Event::assertDispatched(MatchScheduled::class, fn (MatchScheduled $event): bool => $event->matchId === (string) $final->getKey()
        && $event->round === 2
        && $event->scheduledAt === '2026-11-21T15:00:00+00:00'
        // Slot 1 is fed by match 1, slot 2 by match 2.
        && $event->entrantIds === [$a, $c]
        && $event->memberUserIds === [$a => [$members[$a]], $c => [$members[$c]]]);

    // Advancing again fills nothing new, so it announces nothing new.
    app(AdvanceBracket::class)->handle($second->fresh());

    Event::assertDispatchedTimes(MatchScheduled::class, 1);
});

it('announces a round-one match at once, because both its sides are known', function (): void {
    $this->withBrand();

    [$competition] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    Event::fake([MatchScheduled::class]);

    // The per-round action times both round-one matches; each is its own
    // announcement (`ScheduleRound`).
    $moved = app(ScheduleRound::class)->handle($stage, null, 1, CarbonImmutable::parse('2026-11-21 13:00:00', 'UTC'));

    expect($moved)->toBe(2);

    Event::assertDispatchedTimes(MatchScheduled::class, 2);

    // Round two's single match waits on both of round one's: timing it
    // announces nothing yet.
    app(ScheduleRound::class)->handle($stage, null, 2, CarbonImmutable::parse('2026-11-21 15:00:00', 'UTC'));

    Event::assertDispatchedTimes(MatchScheduled::class, 2);
});

it('never announces a bye', function (): void {
    $this->withBrand();

    // Three entrants in a bracket of four: S(4) = [1, 4, 2, 3], and seed 4
    // does not exist, so round one's first match is seed 1 against nobody —
    // an empty slot no match feeds. The second is seed 2 against seed 3.
    [$competition] = competitionFieldWithRosters(3);
    $stage = competitionGeneratedStage($competition);

    $bye = competitionMatchAt($stage, 1, 1);
    $played = competitionMatchAt($stage, 1, 2);

    expect(competitionSidesOf($bye))->toHaveCount(1)
        ->and(competitionSidesOf($played))->toHaveCount(2);

    Event::fake([MatchScheduled::class]);

    app(ScheduleMatch::class)->handle($bye, CarbonImmutable::parse('2026-11-21 13:00:00', 'UTC'));

    Event::assertNotDispatched(MatchScheduled::class);

    app(ScheduleMatch::class)->handle($played, CarbonImmutable::parse('2026-11-21 13:00:00', 'UTC'));

    Event::assertDispatchedTimes(MatchScheduled::class, 1);
    Event::assertDispatched(MatchScheduled::class, fn (MatchScheduled $event): bool => $event->matchId === (string) $played->getKey());
});
