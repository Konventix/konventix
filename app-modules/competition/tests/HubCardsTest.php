<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Data\MyCompetitionEntryData;
use Konventix\Competition\Data\MyCompetitionsCardData;
use Konventix\Competition\Data\NextGameData;
use Konventix\Competition\Data\NextGamesCardData;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\NextGameNudge;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Hub\MyCompetitionsCard;
use Konventix\Competition\Hub\NextGamesCard;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventStarted;
use Konventix\Identity\Models\User;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Models\Brand;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * The hub's two Competition cards (design §10a, hub design §8, D-HUB-07):
 * *Nächste Spiele* and *Meine Competitions*.
 *
 * The payload tests call the resolvers directly with `hubActiveEventFor()`
 * (Platform's shared helper), because they are about what the *card* makes
 * of the running event, not how the hub resolved it. The gate test goes
 * through `/live` and the registry.
 *
 * Time is frozen at **2026-11-21 12:00 UTC** in every test (`beforeEach`),
 * and every expected order and nudge below is derived by hand from that
 * instant, in a comment next to the assertion.
 *
 * Helper names are prefixed `hubCards` — Pest helpers are process-global
 * across every module's suite (`.ai/rules/tests.md`).
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-21T12:00:00Z'));
});

/**
 * An event as Competition learns of it, through Event's `EventCreated`
 * (ADR-0004), with the slug the card links under and the Europe/Berlin
 * zone its match times are shown in — and then `EventStarted`, so its
 * public tournament pages resolve (a null state is refused, §14).
 */
function hubCardsEvent(Brand $brand, string $slug = 'sxlan-27'): string
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: $slug,
        name: 'SXLan 27',
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    event(new EventStarted(eventId: $eventId, brandId: (string) $brand->getKey(), startedAt: '2026-11-20T09:00:00+00:00'));

    return $eventId;
}

function hubCardsJoin(string $teamId, string $brandId, User $viewer): void
{
    $member = new CompetitionTeamMember([
        'competition_team_id' => $teamId,
        'user_id' => (string) $viewer->getKey(),
        'role' => TeamRole::Member,
    ]);
    $member->forceFill(['brand_id' => $brandId])->save();
}

/**
 * A `Running` single-elimination competition of four at `$eventId`,
 * participant-reported, with the viewer on seed 1's roster. Returns the
 * competition, the viewer's entrant and the viewer's round-1 match.
 *
 * The match is read out of the drawn bracket rather than assumed:
 * which slot the generator gives seed 1 is `BracketMathematicsTest`'s
 * subject, not this file's.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Competition, 1: Entrant, 2: CompetitionMatch, 3: array<string, string>, 4: Stage}
 */
function hubCardsCompetition(string $eventId, User $viewer, string $name, array $attributes = []): array
{
    [$competition, $field, $members] = competitionFieldWithRosters(4, [
        'event_id' => $eventId,
        'name' => $name,
        'slug' => Str::slug($name),
        'result_submission_mode' => ResultSubmissionMode::Participants,
        ...$attributes,
    ]);

    $stage = competitionGeneratedStage($competition);

    // Drawn while `SignupClosed` (§4), played while `Running`.
    $competition->forceFill(['status' => CompetitionStatus::Running])->save();

    $entrant = $field[0];
    hubCardsJoin($entrant->competition_team_id, $entrant->brand_id, $viewer);

    /** @var CompetitionMatch $match */
    $match = CompetitionMatch::query()
        ->where('stage_id', $stage->getKey())
        ->where('round', 1)
        ->whereIn('id', MatchSlot::query()->select('competition_match_id')->where('entrant_id', $entrant->getKey()))
        ->firstOrFail();

    return [$competition->refresh(), $entrant, $match, $members, $stage];
}

function hubCardsScheduleAt(CompetitionMatch $match, string $instant): void
{
    $match->forceFill(['state' => MatchState::Scheduled, 'scheduled_at' => CarbonImmutable::parse($instant)])->save();
}

/**
 * `$winner` wins `$match` 2:0 at the desk — the production path
 * (`RecordResultAtDesk` → `SettlesAMatch::settleMatch()`), which records
 * the result **and** advances the bracket. `RecordMatchResult` alone does
 * not advance anything (fix round 1, C1).
 */
function hubCardsWinAtDesk(CompetitionMatch $match, Entrant $winner): void
{
    $scores = [];

    foreach (competitionSidesOf($match) as $position => $entrantId) {
        $scores[$position] = $entrantId === $winner->getKey() ? 2 : 0;
    }

    app(RecordResultAtDesk::class)->handle($match->fresh(), $scores, $winner->getKey(), (string) Str::ulid());
}

/** The other side of a two-sided match, as an entrant. */
function hubCardsOpponent(CompetitionMatch $match, Entrant $side): Entrant
{
    $opponentId = collect(competitionSidesOf($match))->first(fn (string $id): bool => $id !== $side->getKey());

    /** @var Entrant $opponent */
    $opponent = Entrant::query()->findOrFail($opponentId);

    return $opponent;
}

function hubCardsTeamName(Entrant $entrant): string
{
    /** @var CompetitionTeam $team */
    $team = CompetitionTeam::query()->findOrFail($entrant->competition_team_id);

    return $team->name;
}

/**
 * An entry of the viewer's team in `$competition`, in the given state —
 * for *Meine Competitions*, which needs no bracket.
 */
function hubCardsEntry(Competition $competition, User $viewer, EntrantState $state = EntrantState::Registered): Entrant
{
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();
    hubCardsJoin($team->getKey(), $team->brand_id, $viewer);

    return Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'competition_team_id' => $team->getKey(),
        'state' => $state,
    ]);
}

function hubCardsNextGames(Brand $brand, User $viewer, string $eventId): NextGamesCardData
{
    return app(NextGamesCard::class)(hubActiveEventFor($brand, $viewer, $eventId, (string) Str::ulid()), $viewer);
}

function hubCardsMyCompetitions(Brand $brand, User $viewer, string $eventId): MyCompetitionsCardData
{
    return app(MyCompetitionsCard::class)(hubActiveEventFor($brand, $viewer, $eventId, (string) Str::ulid()), $viewer);
}

/**
 * @return array<string, NextGameData> keyed by competition name
 */
function hubCardsGamesByCompetition(NextGamesCardData $card): array
{
    $rows = [];

    foreach ($card->games as $game) {
        $rows[$game->competitionName] = $game;
    }

    return $rows;
}

/*
 * ---------------------------------------------------------------------------
 * Activation (ADR-0003)
 * ---------------------------------------------------------------------------
 */

/**
 * Both halves on purpose, like Agenda's and Community's card tests: "not
 * visible" alone would also pass if the cards had never been registered.
 */
it('registers both cards at boot and shows them only once the brand activates competition', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    $registry = app(HubRegistry::class);
    $keys = ['competition.next-games', 'competition.my-competitions'];

    $registered = $registry->all()
        ->filter(fn (HubCard|HubLink $item): bool => in_array($item->key, $keys, true))
        ->map(fn (HubCard|HubLink $item): string => $item->key)
        ->values()
        ->all();
    $visible = fn (): array => $registry->visibleCards($user)
        ->filter(fn (HubCard $card): bool => in_array($card->key, $keys, true))
        ->map(fn (HubCard $card): string => $card->key)
        ->values()
        ->all();

    expect($registered)->toBe($keys)
        ->and($visible())->toBe([]);

    $this->activateModule('competition');

    // Sorted by `order`: 40 before 41.
    expect($visible())->toBe($keys);
});

it('renders no competition card on /live with the module off, and both once it is on', function (): void {
    $brand = $this->withBrand();

    $user = User::factory()->create();
    $eventId = (string) Str::ulid();

    // Admitted the way production admits (Platform's shared helper): the
    // hub itself renders, so a missing card is the gate's, not the page's.
    event(hubAdmitted($brand->getKey(), $user->id, $eventId));

    $cardsOf = function () use ($user): array {
        $cards = [];

        $this->actingAs($user)->get('/live')->assertOk()->assertInertia(function (Assert $page) use (&$cards): void {
            /** @var list<array<string, mixed>> $rendered */
            $rendered = $page->toArray()['props']['hub']['cards'];

            foreach ($rendered as $card) {
                $cards[$card['key']] = $card;
            }
        });

        return $cards;
    };

    $off = $cardsOf();

    expect(array_keys($off))->not->toContain('competition.next-games')
        ->and(array_keys($off))->not->toContain('competition.my-competitions');

    $this->activateModule('competition', $brand);

    // The request boundary FPM and Octane give (`.ai/rules/tests.md`).
    $this->app->forgetScopedInstances();

    $on = $cardsOf();

    // This module was never told the event's slug, so the links fall back
    // to the one `hubAdmitted()` carries: `kon-fest`.
    expect($on['competition.next-games']['payload'])->toBe([
        'games' => [],
        'hasMore' => false,
        'myTournamentsUrl' => '/my/tournaments',
        'nextGamesUrl' => '/live/next-games',
        // Nothing projected for this event: the reader's zone, client-side.
        'eventTimezone' => null,
        'pollSeconds' => null,
    ])
        ->and($on['competition.my-competitions']['payload'])->toBe([
            'entries' => [],
            'hasMore' => false,
            'myTournamentsUrl' => '/my/tournaments',
            'tournamentsUrl' => '/events/kon-fest/tournaments',
        ]);
});

it('serves the card\'s own refresh as JSON without rebuilding the hub or counting a view', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();

    hubCardsCompetition($eventId, $viewer, 'Mike');

    // Admitted to *this* event, so the hub's context resolves it.
    event(hubAdmitted($brand->getKey(), $viewer->id, $eventId));

    // Module off: the route is behind `ensure-module:competition`.
    $this->actingAs($viewer)->getJson('/live/next-games')->assertNotFound();

    $this->activateModule('competition', $brand);
    $this->app->forgetScopedInstances();

    // The hub-view counter's total across its series. Only its own sample
    // lines: other counters may well move with a request.
    //
    // The test environment keeps metrics in memory (`PROMETHEUS_STORAGE`
    // is `memory` in phpunit.xml), in the `CollectorRegistry` the container
    // binds `scoped()`. `forgetScopedInstances()` therefore throws every
    // count away, so none may run between the requests this compares — the
    // first version of this test called it after `/live` and read an empty
    // registry in the pipeline. Only one full-page GET happens here, so the
    // SSR reason for forgetting (.ai/rules/tests.md) does not apply.
    $views = fn (): float => (float) collect(explode("\n", (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples())))
        ->filter(fn (string $line): bool => str_starts_with($line, 'konventix_platform_hub_views_total{'))
        ->sum(fn (string $line): float => (float) substr($line, (int) strrpos($line, ' ') + 1));

    expect($views())->toBe(0.0);

    // A real hub render moves the counter, so "unchanged" below cannot pass
    // on a counter that is never written.
    $this->actingAs($viewer)->get('/live')->assertOk();

    expect($views())->toBe(1.0);

    $response = $this->actingAs($viewer)->getJson('/live/next-games')->assertOk();

    // The same payload the hub renders the card with: one row, Mike's
    // unscheduled round-1 match, reportable (rule 4).
    expect($response->json('games'))->toHaveCount(1)
        ->and($response->json('games.0.competitionName'))->toBe('Mike')
        ->and($response->json('games.0.nudge'))->toBe('report')
        ->and($response->json('eventTimezone'))->toBe('Europe/Berlin')
        ->and($response->json('pollSeconds'))->toBe(NextGamesCard::POLL_SECONDS)
        // Not a hub render: the page counter does not move (I1).
        ->and($views())->toBe(1.0);

    $this->app->forgetScopedInstances();

    // Signed in but admitted nowhere: the hub's gate, answered as a 404.
    $this->actingAs($stranger)->getJson('/live/next-games')->assertNotFound();
});

/*
 * ---------------------------------------------------------------------------
 * Nächste Spiele
 * ---------------------------------------------------------------------------
 */

it('gives a viewer with no entries both empty states', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    // Somebody else's tournament at the same event: there is something to
    // play here, just nothing of the viewer's.
    hubCardsCompetition($eventId, User::factory()->create(), 'Main Cup');

    $games = hubCardsNextGames($brand, $viewer, $eventId);
    $entries = hubCardsMyCompetitions($brand, $viewer, $eventId);

    expect($games->games)->toBe([])
        ->and($games->hasMore)->toBeFalse()
        // Nothing of the viewer's runs, so nothing to poll for.
        ->and($games->pollSeconds)->toBeNull()
        ->and($entries->entries)->toBe([])
        ->and($entries->hasMore)->toBeFalse()
        // The empty state's link: the event's tournament index, under the
        // slug Competition learned from `EventCreated`.
        ->and($entries->tournamentsUrl)->toBe('/events/sxlan-27/tournaments');
});

it('orders the viewer\'s open matches scheduled first and soonest first, then by round, and shows five', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    // Six competitions, one open match of the viewer's in each. Now is
    // 12:00 UTC.
    [, $alphaSide, $alpha] = hubCardsCompetition($eventId, $viewer, 'Alpha');
    hubCardsScheduleAt($alpha, '2026-11-21T14:00:00Z');

    // Recorded by the desk only, so the side has nothing to report.
    [, $bravoSide, $bravo] = hubCardsCompetition($eventId, $viewer, 'Bravo', [
        'result_submission_mode' => ResultSubmissionMode::DeskOnly,
    ]);
    hubCardsScheduleAt($bravo, '2026-11-21T11:30:00Z');

    // Unscheduled, round 1.
    [, $charlieSide, $charlie] = hubCardsCompetition($eventId, $viewer, 'Charlie');

    // The viewer won round 1, so their open match is round 2 — unscheduled,
    // with the other semi-final still undecided.
    [, $deltaSide, $deltaFirst] = hubCardsCompetition($eventId, $viewer, 'Delta');
    hubCardsWinAtDesk($deltaFirst, $deltaSide);

    [, , $echo] = hubCardsCompetition($eventId, $viewer, 'Echo');
    hubCardsScheduleAt($echo, '2026-11-21T13:00:00Z');

    [, , $foxtrot] = hubCardsCompetition($eventId, $viewer, 'Foxtrot');
    hubCardsScheduleAt($foxtrot, '2026-11-21T15:00:00Z');

    $card = hubCardsNextGames($brand, $viewer, $eventId);

    // Derivation. Delta's round-1 match is finished (the desk settled it)
    // and not a row; the desk path advanced the viewer into Delta's final,
    // round 2, whose other slot waits on the unplayed other semi-final — an
    // open match of the viewer's. So there are six open matches, one per
    // competition. Scheduled rows first, by time: Bravo 11:30, Echo 13:00,
    // Alpha 14:00, Foxtrot 15:00. Then the unscheduled, by round: Charlie
    // (round 1) before Delta (round 2). Six rows, five shown: Delta is the
    // sixth, left for "Meine Turniere", so `hasMore` is true.
    expect(array_map(fn (NextGameData $game): string => $game->competitionName, $card->games))
        ->toBe(['Bravo', 'Echo', 'Alpha', 'Foxtrot', 'Charlie'])
        ->and($card->hasMore)->toBeTrue()
        // Every competition here is `Running`.
        ->and($card->pollSeconds)->toBe(NextGamesCard::POLL_SECONDS)
        // Projected from `EventCreated` (§10a "Time").
        ->and($card->eventTimezone)->toBe('Europe/Berlin')
        ->and($card->nextGamesUrl)->toBe('/live/next-games');

    $rows = hubCardsGamesByCompetition($card);

    // Bravo: 11:30 ≤ 12:00 and both sides are there, so it is due; it is
    // `desk_only`, so the side cannot report (`canReport` false) and rule 4
    // (report) does not apply — rule 5, "Jetzt dran".
    expect($rows['Bravo']->nudge)->toBe(NextGameNudge::Due)
        ->and($rows['Bravo']->scheduledAt)->toBe('2026-11-21T11:30:00+00:00')
        ->and($rows['Bravo']->round)->toBe(1)
        ->and($rows['Bravo']->bracket)->toBeNull()
        ->and($rows['Bravo']->opponentName)->toBe(hubCardsTeamName(hubCardsOpponent($bravo, $bravoSide)))
        ->and($rows['Bravo']->awaitingOpponent)->toBeFalse()
        ->and($rows['Bravo']->matchUrl)->toBe("/events/sxlan-27/tournaments/bravo/matches/{$bravo->getKey()}")
        // Echo 13:00, Alpha 14:00, Foxtrot 15:00: later than now, nothing
        // reported — no nudge, only the time.
        ->and($rows['Echo']->nudge)->toBeNull()
        ->and($rows['Alpha']->nudge)->toBeNull()
        ->and($rows['Alpha']->opponentName)->toBe(hubCardsTeamName(hubCardsOpponent($alpha, $alphaSide)))
        ->and($rows['Foxtrot']->nudge)->toBeNull()
        // Charlie: no time, both sides there, participant-reported → "Ergebnis
        // melden" (rule 4).
        ->and($rows['Charlie']->nudge)->toBe(NextGameNudge::Report)
        ->and($rows['Charlie']->scheduledAt)->toBeNull()
        ->and($rows['Charlie']->opponentName)->toBe(hubCardsTeamName(hubCardsOpponent($charlie, $charlieSide)));
});

it('says "wartet auf Gegner" while the other slot waits on an undecided match', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    [, $side, $first] = hubCardsCompetition($eventId, $viewer, 'Delta');
    hubCardsWinAtDesk($first, $side);

    $card = hubCardsNextGames($brand, $viewer, $eventId);

    // Derivation: the desk settled round 1 and advanced the viewer, so the
    // only open match is the final, round 2 (one row, `hasMore` false). Its other slot is fed by the other semi-final, which nobody
    // has played: no opponent name, `awaitingOpponent` set. No nudge either
    // — it has no time (not due) and a side is missing (nothing to report).
    expect($card->games)->toHaveCount(1)
        ->and($card->games[0]->round)->toBe(2)
        ->and($card->games[0]->opponentName)->toBeNull()
        ->and($card->games[0]->awaitingOpponent)->toBeTrue()
        ->and($card->games[0]->nudge)->toBeNull()
        ->and($card->hasMore)->toBeFalse();
});

it('asks for the report on an overdue match nobody has reported, rather than "Jetzt dran" forever', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    [, , $match] = hubCardsCompetition($eventId, $viewer, 'Kilo');
    hubCardsScheduleAt($match, '2026-11-21T10:00:00Z');

    $rows = hubCardsGamesByCompetition(hubCardsNextGames($brand, $viewer, $eventId));

    // Derivation: 10:00 ≤ 12:00, so it is due; participant-reported, both
    // sides there, no proof required, nothing reported, not disputed, so
    // `canReport` is true. Rules 1–3 do not apply; rule 4 — may report, and
    // due — gives "Ergebnis melden" (fix round 1, M1).
    expect($rows['Kilo']->nudge)->toBe(NextGameNudge::Report);
});

it('nudges to confirm, waits on a confirmation, and flags a dispute from the reports on the match', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();
    $report = app(ReportMatchResult::class);

    // Golf: the other side reported 2:0 for themselves; the viewer's side
    // has not answered.
    [, $golfSide, $golf, $golfMembers] = hubCardsCompetition($eventId, $viewer, 'Golf');
    $golfOpponent = hubCardsOpponent($golf, $golfSide);
    $report->handle($golf, $golfOpponent, new MatchReportData(scores: [1 => 0, 2 => 2]), $golfMembers[$golfOpponent->getKey()]);

    // Hotel: the viewer reported; the other side has not answered.
    [, $hotelSide, $hotel] = hubCardsCompetition($eventId, $viewer, 'Hotel');
    $report->handle($hotel, $hotelSide, new MatchReportData(scores: [1 => 2, 2 => 0]), (string) $viewer->getKey());

    // India: both sides reported, and they disagree — §8.2 makes that a
    // dispute, open until the desk decides.
    [, $indiaSide, $india, $indiaMembers] = hubCardsCompetition($eventId, $viewer, 'India');
    $indiaOpponent = hubCardsOpponent($india, $indiaSide);
    $report->handle($india, $indiaOpponent, new MatchReportData(scores: [1 => 0, 2 => 2]), $indiaMembers[$indiaOpponent->getKey()]);
    $report->handle($india->fresh(), $indiaSide, new MatchReportData(scores: [1 => 2, 2 => 0]), (string) $viewer->getKey());

    expect($india->fresh()->isDisputed())->toBeTrue();

    $rows = hubCardsGamesByCompetition(hubCardsNextGames($brand, $viewer, $eventId));

    // Derivation, per §10a's precedence. None of the three has a time, so
    // "due" never competes.
    // Golf: not disputed; another side's report and none of the viewer's
    // → the viewer may confirm (rule 2).
    // Hotel: not disputed; nobody may confirm the viewer's own claim; the
    // viewer's report stands alone (rule 3). It would also be reportable
    // again (a side may replace its claim), but rule 3 comes first.
    // India: disputed (rule 1), although both reports are there.
    expect($rows)->toHaveKeys(['Golf', 'Hotel', 'India'])
        ->and($rows['Golf']->nudge)->toBe(NextGameNudge::Confirm)
        ->and($rows['Hotel']->nudge)->toBe(NextGameNudge::AwaitingConfirmation)
        ->and($rows['India']->nudge)->toBe(NextGameNudge::Disputed);
});

it('drops a displaced side\'s claim from the card once a correction re-pairs the match', function (): void {
    // Final review, part 4, I1. S(4) = [1, 4, 2, 3]: round 1 is seed 1 (P,
    // the viewer's side) v seed 4 (Q) and seed 2 (R) v seed 3 (S); the final
    // takes match 1's winner in slot 1 and match 2's in slot 2.
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $ofP = User::factory()->create();

    [$competition, $p, $first, , $stage] = hubCardsCompetition($eventId, $ofP, 'Juliett');
    $q = hubCardsOpponent($first, $p);
    $second = competitionMatchAt($stage, 1, 2);
    $r = Entrant::query()->findOrFail(competitionSidesOf($second)[1]);

    $ofQ = User::factory()->create();
    hubCardsJoin($q->competition_team_id, $q->brand_id, $ofQ);
    $ofR = User::factory()->create();
    hubCardsJoin($r->competition_team_id, $r->brand_id, $ofR);

    // P beats Q, R beats S; P claims the final 16:12.
    hubCardsWinAtDesk($first, $p);
    hubCardsWinAtDesk($second, $r);
    $final = competitionMatchAt($stage, 2, 1);
    app(ReportMatchResult::class)->handle($final, $p, new MatchReportData(scores: [1 => 16, 2 => 12]), (string) $ofP->getKey());

    // Before the correction R is asked to confirm P's claim — the control.
    expect(hubCardsGamesByCompetition(hubCardsNextGames($brand, $ofR, $eventId))['Juliett']->nudge)->toBe(NextGameNudge::Confirm);

    // Match 1 was Q's: Q replaces P in the final's slot 1.
    app(CorrectMatchResult::class)->handle($first->fresh(), [$p->getKey() => 0, $q->getKey() => 2], $q->getKey(), (string) Str::ulid());

    $forP = hubCardsGamesByCompetition(hubCardsNextGames($brand, $ofP, $eventId));
    $forQ = hubCardsGamesByCompetition(hubCardsNextGames($brand, $ofQ, $eventId));
    $forR = hubCardsGamesByCompetition(hubCardsNextGames($brand, $ofR, $eventId));

    // P has no open match left in Juliett, so no row. Q and R now meet in a
    // final with no time and no claim on it: nobody has reported, so the
    // nudge is to report (§10a rule 4), not to confirm P's 16:12. Before the
    // fix P's claim stayed and both were told "Ergebnis bestätigen".
    expect($forP)->not->toHaveKey('Juliett')
        ->and($forQ['Juliett']->matchId)->toBe($final->getKey())
        ->and($forQ['Juliett']->opponentName)->toBe(hubCardsTeamName($r))
        ->and($forQ['Juliett']->nudge)->toBe(NextGameNudge::Report)
        ->and($forR['Juliett']->opponentName)->toBe(hubCardsTeamName($q))
        ->and($forR['Juliett']->nudge)->toBe(NextGameNudge::Report);

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);
});

it('reads only claims by a side standing in the match now, on the card, the match page and Meine Turniere alike', function (): void {
    // Fix wave B, item 11. The correction cascade drops a re-paired match's
    // claims (wave A), and the Actions read only current sides' claims; the
    // readers must apply the same rule, or they offer a confirm the Action
    // refuses. The claim below is left behind on purpose — the slot moves
    // without the cascade — which is exactly the row the rule is for.
    //
    // S(4) = [1, 4, 2, 3]: P (seed 1, viewer's side) v Q (seed 4) and R
    // (seed 2) v S (seed 3); the final takes match 1's winner in slot 1.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = hubCardsEvent($brand);
    $ofP = User::factory()->create();

    [$competition, $p, $first, , $stage] = hubCardsCompetition($eventId, $ofP, 'Kilo Cup');
    $q = hubCardsOpponent($first, $p);
    $second = competitionMatchAt($stage, 1, 2);
    $r = Entrant::query()->findOrFail(competitionSidesOf($second)[1]);

    $ofR = User::factory()->create();
    hubCardsJoin($r->competition_team_id, $r->brand_id, $ofR);

    hubCardsWinAtDesk($first, $p);
    hubCardsWinAtDesk($second, $r);
    $final = competitionMatchAt($stage, 2, 1);
    app(ReportMatchResult::class)->handle($final, $p, new MatchReportData(scores: [1 => 16, 2 => 12]), (string) $ofP->getKey());

    $matchUrl = '/events/sxlan-27/tournaments/kilo-cup/matches/'.$final->getKey();

    // The control: while P stands in slot 1, R is asked to confirm P's claim
    // on the card, on the match page and on Meine Turniere.
    expect(hubCardsGamesByCompetition(hubCardsNextGames($brand, $ofR, $eventId))['Kilo Cup']->nudge)->toBe(NextGameNudge::Confirm);

    $this->actingAs($ofR)->get($matchUrl)->assertInertia(fn (Assert $page) => $page
        ->where('page.viewer.opposingReport', [1 => 16, 2 => 12])
        ->where('page.viewer.canConfirm', true));

    $this->app->forgetScopedInstances();
    $this->actingAs($ofR)->get('/my/tournaments')->assertInertia(fn (Assert $page) => $page
        ->has('pendingConfirmations', 1));

    // Q takes P's slot, and P's claim stays behind.
    MatchSlot::query()
        ->where('competition_match_id', $final->getKey())
        ->where('entrant_id', $p->getKey())
        ->firstOrFail()
        ->forceFill(['entrant_id' => $q->getKey()])
        ->save();

    // Derivation for R, per §10a: no time, so never due; not disputed (rule
    // 1); P's claim is by no side of the match, so there is nothing to
    // confirm (rule 2) and R has no claim of its own (rule 3); participants,
    // open, Q and R both there, no proof required → R may report (rule 4).
    expect(hubCardsGamesByCompetition(hubCardsNextGames($brand, $ofR, $eventId))['Kilo Cup']->nudge)->toBe(NextGameNudge::Report);

    $this->app->forgetScopedInstances();
    $this->actingAs($ofR)->get($matchUrl)->assertInertia(fn (Assert $page) => $page
        ->where('page.viewer.opposingReport', null)
        ->where('page.viewer.canConfirm', false)
        ->where('page.viewer.canReport', true));

    $this->app->forgetScopedInstances();
    $this->actingAs($ofR)->get('/my/tournaments')->assertInertia(fn (Assert $page) => $page
        ->has('pendingConfirmations', 0));

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);
});

it('shows match times on the event\'s wall clock, projected from EventCreated and kept by EventRescheduled', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    $this->activateModule('competition', $brand);

    [, , $match] = hubCardsCompetition($eventId, $viewer, 'Lima');
    hubCardsScheduleAt($match, '2026-11-21T18:00:00Z');

    $card = hubCardsNextGames($brand, $viewer, $eventId);

    // The instant goes out unchanged, with the event's zone beside it; the
    // component renders it on that zone's clock (`formatEventDateTime()`,
    // `eventTime.test.ts`). 2026-11-21 is after Berlin's summer time ended
    // (2026-10-25), so CET, UTC+1: 18:00 UTC is 19:00 in the hall — not the
    // 20:00 a CEST date would give.
    expect($card->games[0]->scheduledAt)->toBe('2026-11-21T18:00:00+00:00')
        ->and($card->eventTimezone)->toBe('Europe/Berlin');

    // The match page (Task 7) carries the same zone on its event.
    $this->get("/events/sxlan-27/tournaments/lima/matches/{$match->getKey()}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.event.timezone', 'Europe/Berlin')
            ->where('page.match.scheduledAt', '2026-11-21T18:00:00+00:00'));

    // A reschedule into another zone moves both — the same handler
    // `event:replay-details` reaches, which is the backfill for rows written
    // before the column existed.
    event(new EventRescheduled(
        eventId: $eventId,
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/London',
        brandId: (string) $brand->getKey(),
    ));

    expect(hubCardsNextGames($brand, $viewer, $eventId)->eventTimezone)->toBe('Europe/London');
});

it('never lists another event\'s or another brand\'s matches or entries', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    hubCardsCompetition($eventId, $viewer, 'Eigenes Turnier');

    // The same viewer at another event of the same brand.
    hubCardsCompetition(hubCardsEvent($brand, 'sxlan-28'), $viewer, 'Anderes Event');

    // The same viewer at another brand — under the *same* event ULID, so
    // only the brand keeps it out, not the event filter.
    $other = Brand::factory()->create();
    $this->withBrand($other);
    hubCardsCompetition($eventId, $viewer, 'Andere Marke');
    $this->withBrand($brand);

    $games = hubCardsNextGames($brand, $viewer, $eventId);
    $entries = hubCardsMyCompetitions($brand, $viewer, $eventId);

    expect(array_map(fn (NextGameData $game): string => $game->competitionName, $games->games))
        ->toBe(['Eigenes Turnier'])
        ->and(array_map(fn (MyCompetitionEntryData $entry): string => $entry->competitionName, $entries->entries))
        ->toBe(['Eigenes Turnier']);
});

/*
 * ---------------------------------------------------------------------------
 * Meine Competitions
 * ---------------------------------------------------------------------------
 */

it('lists the viewer\'s entries with status and check-in, dated ones first, and shows five', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    $at = fn (string $name, ?string $startsAt, array $attributes = []): Competition => Competition::factory()->forEvent($eventId)->create([
        'name' => $name,
        'slug' => Str::slug($name),
        'starts_at' => $startsAt === null ? null : CarbonImmutable::parse($startsAt),
        ...$attributes,
    ]);

    // Check-in at the desk, and the team has checked in.
    hubCardsEntry($at('Kilo', '2026-11-21T18:00:00Z', ['check_in_mode' => CheckInMode::Desk]), $viewer, EntrantState::CheckedIn);
    // Check-in at the desk, not done yet; the competition is running.
    hubCardsEntry($at('Lima', '2026-11-21T10:00:00Z', [
        'check_in_mode' => CheckInMode::Desk,
        'status' => CompetitionStatus::Running,
    ]), $viewer);
    hubCardsEntry($at('Mike', null), $viewer);
    // Incomplete still holds its place (§5.4), so it is an entry.
    hubCardsEntry($at('November', '2026-11-22T09:00:00Z'), $viewer, EntrantState::Incomplete);
    hubCardsEntry($at('Oscar', null), $viewer);
    hubCardsEntry($at('Papa', null), $viewer);

    // Not entries: a withdrawn team, and a draft the public may not see.
    hubCardsEntry($at('Quebec', '2026-11-21T08:00:00Z'), $viewer, EntrantState::Withdrawn);
    hubCardsEntry($at('Romeo', '2026-11-21T08:00:00Z', ['status' => CompetitionStatus::Draft, 'published_at' => null]), $viewer);

    $card = hubCardsMyCompetitions($brand, $viewer, $eventId);

    // Derivation. Quebec (withdrawn) and Romeo (draft) drop out, leaving
    // six. Dated first, soonest first: Lima 21st 10:00, Kilo 21st 18:00,
    // November 22nd 09:00. Then undated by name: Mike, Oscar, Papa. Five
    // shown, so Papa is left for "Meine Turniere" and `hasMore` is set.
    expect(array_map(fn (MyCompetitionEntryData $entry): string => $entry->competitionName, $card->entries))
        ->toBe(['Lima', 'Kilo', 'November', 'Mike', 'Oscar'])
        ->and($card->hasMore)->toBeTrue()
        ->and($card->myTournamentsUrl)->toBe('/my/tournaments');

    [$lima, $kilo, $november] = $card->entries;

    expect($lima->status)->toBe(CompetitionStatus::Running)
        ->and($lima->checkInRequired)->toBeTrue()
        ->and($lima->checkedIn)->toBeFalse()
        ->and($lima->competitionUrl)->toBe('/events/sxlan-27/tournaments/lima')
        ->and($kilo->status)->toBe(CompetitionStatus::Published)
        ->and($kilo->checkInRequired)->toBeTrue()
        ->and($kilo->checkedIn)->toBeTrue()
        // The factory's default mode is `none`: nothing to check in to.
        ->and($november->checkInRequired)->toBeFalse()
        ->and($november->checkedIn)->toBeFalse();
});
