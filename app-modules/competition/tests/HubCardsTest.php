<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Konventix\Competition\Actions\RecordMatchResult;
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
use Konventix\Identity\Models\User;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Models\Brand;

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
 * (ADR-0004), with the slug the card links under.
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
        'pollSeconds' => null,
    ])
        ->and($on['competition.my-competitions']['payload'])->toBe([
            'entries' => [],
            'hasMore' => false,
            'myTournamentsUrl' => '/my/tournaments',
            'tournamentsUrl' => '/events/kon-fest/tournaments',
        ]);
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

    [, $bravoSide, $bravo] = hubCardsCompetition($eventId, $viewer, 'Bravo');
    hubCardsScheduleAt($bravo, '2026-11-21T11:30:00Z');

    // Unscheduled, round 1.
    [, $charlieSide, $charlie] = hubCardsCompetition($eventId, $viewer, 'Charlie');

    // The viewer won round 1, so their open match is round 2 — unscheduled,
    // with the other semi-final still undecided.
    [, $deltaSide, $deltaFirst] = hubCardsCompetition($eventId, $viewer, 'Delta');
    app(RecordMatchResult::class)->handle(
        $deltaFirst,
        [$deltaSide->getKey() => 2, hubCardsOpponent($deltaFirst, $deltaSide)->getKey() => 0],
        $deltaSide->getKey(),
    );

    [, , $echo] = hubCardsCompetition($eventId, $viewer, 'Echo');
    hubCardsScheduleAt($echo, '2026-11-21T13:00:00Z');

    [, , $foxtrot] = hubCardsCompetition($eventId, $viewer, 'Foxtrot');
    hubCardsScheduleAt($foxtrot, '2026-11-21T15:00:00Z');

    $card = hubCardsNextGames($brand, $viewer, $eventId);

    // Derivation. Scheduled rows first, by time: Bravo 11:30, Echo 13:00,
    // Alpha 14:00, Foxtrot 15:00. Then the unscheduled, by round: Charlie
    // (round 1) before Delta (round 2). Six rows, five shown: Delta is the
    // one left for "Meine Turniere", and `hasMore` says so. Delta's round-1
    // match is finished and is not a row at all.
    expect(array_map(fn (NextGameData $game): string => $game->competitionName, $card->games))
        ->toBe(['Bravo', 'Echo', 'Alpha', 'Foxtrot', 'Charlie'])
        ->and($card->hasMore)->toBeTrue()
        // Every competition here is `Running`.
        ->and($card->pollSeconds)->toBe(NextGamesCard::POLL_SECONDS);

    $rows = hubCardsGamesByCompetition($card);

    // Bravo: 11:30 ≤ 12:00 and both sides are there, so "Jetzt dran". It
    // may also be reported, but due outranks report (§10a, rule 4).
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
        // melden" (rule 5).
        ->and($rows['Charlie']->nudge)->toBe(NextGameNudge::Report)
        ->and($rows['Charlie']->scheduledAt)->toBeNull()
        ->and($rows['Charlie']->opponentName)->toBe(hubCardsTeamName(hubCardsOpponent($charlie, $charlieSide)));
});

it('says "wartet auf Gegner" while the other slot waits on an undecided match', function (): void {
    $brand = $this->withBrand();
    $eventId = hubCardsEvent($brand);
    $viewer = User::factory()->create();

    [, $side, $first] = hubCardsCompetition($eventId, $viewer, 'Delta');
    app(RecordMatchResult::class)->handle(
        $first,
        [$side->getKey() => 2, hubCardsOpponent($first, $side)->getKey() => 0],
        $side->getKey(),
    );

    $card = hubCardsNextGames($brand, $viewer, $eventId);

    // Derivation: round 1 is finished, so the only open match is the final,
    // round 2. Its other slot is fed by the other semi-final, which nobody
    // has played: no opponent name, `awaitingOpponent` set. No nudge either
    // — it has no time (not due) and a side is missing (nothing to report).
    expect($card->games)->toHaveCount(1)
        ->and($card->games[0]->round)->toBe(2)
        ->and($card->games[0]->opponentName)->toBeNull()
        ->and($card->games[0]->awaitingOpponent)->toBeTrue()
        ->and($card->games[0]->nudge)->toBeNull()
        ->and($card->hasMore)->toBeFalse();
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
