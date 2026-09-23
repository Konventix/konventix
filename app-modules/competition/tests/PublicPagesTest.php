<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\RecordResultAtDesk;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Desk\TournamentDesk;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\EventSummary;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The public and participant pages (design §10, D-CMP-22) and the
 * participant writes behind them (§5.2, §8.2–§8.4).
 *
 * Every page renders for a competition the public may see and 404s for a
 * draft (§4) and for another brand's competition on this brand's host; the
 * bracket the canvas and the table both read carries the generator's
 * pairings; polling is on while `Running` and off otherwise.
 *
 * `Identity\Models\User` appears here only because `actingAs()` needs a real
 * account row; no file under `src/` imports it (`GameOpsBoundaryTest`).
 *
 * Helper names are process-global across every module (.ai/rules/tests.md),
 * so the ones only this file uses are prefixed `publicPages`.
 */

/**
 * An event as Competition learns of it: through Event's `EventCreated`,
 * never by writing the read model by hand (ADR-0004).
 */
function publicPagesEvent(Brand $brand, string $slug = 'sxlan-27', string $name = 'SXLan 27'): string
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: $slug,
        name: $name,
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    return $eventId;
}

/**
 * A real account on an entrant's roster, so a request can act as a member
 * of that side. `competitionFieldWithRosters()` gives each entrant a captain
 * with a bare ULID; this adds a person who can sign in.
 */
function publicPagesMember(Entrant $entrant, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();

    $member = new CompetitionTeamMember([
        'competition_team_id' => $entrant->competition_team_id,
        'user_id' => (string) $user->getKey(),
        'role' => $role,
    ]);
    $member->forceFill(['brand_id' => $entrant->brand_id])->save();

    return $user;
}

/**
 * Four entrants in single elimination at the given event, drawn:
 *   round 1, match 1: seed 1 ($a) v seed 4 ($d)
 *   round 1, match 2: seed 2 ($b) v seed 3 ($c)
 *   round 2, match 1: the two winners
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Competition, 1: list<Entrant>, 2: array<string, string>, 3: Stage}
 */
function publicPagesBracket(string $eventId, array $attributes = []): array
{
    [$competition, $field, $members] = competitionFieldWithRosters(4, [
        'event_id' => $eventId,
        'slug' => 'main-cup',
        'result_submission_mode' => ResultSubmissionMode::Participants,
        ...$attributes,
    ]);

    $stage = competitionGeneratedStage($competition);

    return [$competition, $field, $members, $stage];
}

function publicPagesUrl(Competition $competition, string $suffix = ''): string
{
    return '/events/sxlan-27/tournaments/'.$competition->slug.$suffix;
}

/*
 * ---------------------------------------------------------------------------
 * The event read model (§10, ADR-0004)
 * ---------------------------------------------------------------------------
 */

it('learns an event\'s slug and name from Event and follows a rename', function (): void {
    $brand = $this->withBrand();

    $eventId = publicPagesEvent($brand);

    // `event:replay-details` re-emits this for every event, which is also how
    // an event that predates the projection gets its row.
    event(new EventDetailsUpdated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: 'sxlan-27-herbst',
        name: 'SXLan 27 Herbst',
    ));

    $rows = EventSummary::query()->where('event_id', $eventId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->slug)->toBe('sxlan-27-herbst')
        ->and($rows->first()->name)->toBe('SXLan 27 Herbst');
});

/*
 * ---------------------------------------------------------------------------
 * Every page renders for a published competition and 404s for a draft (§4)
 * ---------------------------------------------------------------------------
 */

it('lists the event\'s competitions without the drafts', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    Competition::factory()->forEvent($eventId)->create(['name' => 'Main Cup', 'slug' => 'main-cup']);
    Competition::factory()->forEvent($eventId)->unpublished()->create(['name' => 'Secret Cup', 'slug' => 'secret-cup']);
    // Another event of the same brand is not this event's.
    Competition::factory()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);

    $this->get('/events/sxlan-27/tournaments')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('competition/EventTournaments')
            ->where('page.event.name', 'SXLan 27')
            ->has('page.competitions', 1)
            ->where('page.competitions.0.slug', 'main-cup')
            ->where('page.competitions.0.status', 'published'));

    $this->get('/events/no-such-event/tournaments')->assertNotFound();
});

it('renders the competition, match and team pages of a published competition and 404s all three for a draft', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a], , $stage] = publicPagesBracket($eventId);
    $match = competitionMatchAt($stage, 1, 1);
    $team = $a->competition_team_id;

    $pages = [
        [publicPagesUrl($competition), 'competition/Competition'],
        [publicPagesUrl($competition, '/matches/'.$match->id), 'competition/Match'],
        [publicPagesUrl($competition, '/teams/'.$team), 'competition/Team'],
    ];

    foreach ($pages as [$url, $component]) {
        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component));

        $this->app->forgetScopedInstances();
    }

    // Unpublished again: every page is gone, not merely emptied.
    $this->withBrand();
    $competition->forceFill(['status' => CompetitionStatus::Draft, 'published_at' => null])->save();

    foreach ($pages as [$url]) {
        $this->get($url)->assertNotFound();
    }
});

it('404s a match or a team that does not belong to the competition in the address', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a]] = publicPagesBracket($eventId);
    [$other, [$x], , $otherStage] = publicPagesBracket($eventId, ['slug' => 'side-cup']);

    // A team that is not entered here, and a match of the other bracket.
    $this->get(publicPagesUrl($competition, '/teams/'.$x->competition_team_id))->assertNotFound();
    $this->get(publicPagesUrl($competition, '/matches/'.competitionMatchAt($otherStage, 1, 1)->id))->assertNotFound();
    $this->get(publicPagesUrl($other, '/teams/'.$a->competition_team_id))->assertNotFound();
});

/*
 * ---------------------------------------------------------------------------
 * Another brand's competition is never reachable on this brand's host
 * ---------------------------------------------------------------------------
 */

it('never shows another brand\'s competition, match or team on this brand\'s host', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    // The other brand holds the SAME event slug and competition slug, so
    // nothing but the brand tells the two apart.
    $foreign = Brand::factory()->withDomain('other-brand.test')->create();
    $this->withBrand($foreign);
    $foreignEvent = publicPagesEvent($foreign);
    [$theirs, [$their], $theirMembers, $theirStage] = publicPagesBracket($foreignEvent);
    $theirMatch = competitionMatchAt($theirStage, 1, 1);
    $theirMember = publicPagesMember($their);

    $this->withBrand($brand);

    // This brand has no such event: the index and every page 404.
    $this->get('/events/sxlan-27/tournaments')->assertNotFound();
    $this->get(publicPagesUrl($theirs))->assertNotFound();
    $this->get(publicPagesUrl($theirs, '/matches/'.$theirMatch->id))->assertNotFound();
    $this->get(publicPagesUrl($theirs, '/teams/'.$their->competition_team_id))->assertNotFound();

    // Its own event of the same slug does not open the other brand's either.
    publicPagesEvent($brand);
    $this->get(publicPagesUrl($theirs))->assertNotFound();

    // Nor can a write reach it, even by a member of that side.
    $this->actingAs($theirMember)
        ->post('/tournaments/'.$theirs->id.'/matches/'.$theirMatch->id.'/report', ['scores' => [1 => 16, 2 => 9]])
        ->assertNotFound();

    expect(MatchResultReport::withoutBrandScope()->where('competition_match_id', $theirMatch->id)->count())->toBe(0)
        ->and($theirMembers)->toHaveCount(4);
});

/*
 * ---------------------------------------------------------------------------
 * The bracket: one `BracketData` for the canvas and the table (D-CMP-22)
 * ---------------------------------------------------------------------------
 */

it('sends the canvas and the table one bracket carrying the drawn pairings and the graph between them', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a, $b, $c, $d], , $stage] = publicPagesBracket($eventId);

    $final = competitionMatchAt($stage, 2, 1);

    // S(4) = [1, 4, 2, 3]: round 1 is seed 1 v 4 and seed 2 v 3; the final
    // takes match 1's winner in slot 1 and match 2's in slot 2, both still
    // to come. `BracketCanvas` and `BracketTable` are both handed exactly
    // this prop — `bracketModel.test.ts` pins that they render it the same.
    $this->get(publicPagesUrl($competition))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.bracket.format', 'single_elimination')
            ->has('page.bracket.matches', 3)
            ->where('page.bracket.matches.0.round', 1)
            ->where('page.bracket.matches.0.position', 1)
            ->where('page.bracket.matches.0.sides.0.entrantId', $a->id)
            ->where('page.bracket.matches.0.sides.1.entrantId', $d->id)
            ->where('page.bracket.matches.1.sides.0.entrantId', $b->id)
            ->where('page.bracket.matches.1.sides.1.entrantId', $c->id)
            ->where('page.bracket.matches.2.id', $final->id)
            ->where('page.bracket.matches.2.round', 2)
            ->where('page.bracket.matches.2.sides.0.entrantId', null)
            ->where('page.bracket.matches.2.sides.0.awaiting', true)
            ->where('page.bracket.matches.2.sides.1.awaiting', true)
            // Team names and ids for the table's text and links.
            ->where('page.bracket.matches.0.sides.0.teamId', $a->competition_team_id)
            ->where('page.bracket.matches.0.sides.0.teamName', CompetitionTeam::query()->findOrFail($a->competition_team_id)->name)
            ->has('page.bracket.connections', 2)
            ->where('page.bracket.connections', fn ($edges): bool => collect($edges)
                ->map(fn (array $edge): string => $edge['outcome'].'>'.$edge['targetMatchId'].'#'.$edge['targetSlot'])
                ->sort()
                ->values()
                ->all() === ['winner>'.$final->id.'#1', 'winner>'.$final->id.'#2']));
});

it('sends no bracket before one is drawn, and a leaderboard its standings instead', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    Competition::factory()->forEvent($eventId)->create(['slug' => 'main-cup']);

    [$board] = competitionFieldWithRosters(2, ['event_id' => $eventId, 'slug' => 'trackmania']);
    Stage::factory()->leaderboard()->create(['competition_id' => $board->id]);

    $this->get('/events/sxlan-27/tournaments/main-cup')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.bracket', null));

    $this->app->forgetScopedInstances();

    $this->get('/events/sxlan-27/tournaments/trackmania')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.bracket', null)
            ->where('page.competition.format', 'leaderboard'));
});

/*
 * ---------------------------------------------------------------------------
 * Polling while `Running`, and not otherwise (§10 "Freshness")
 * ---------------------------------------------------------------------------
 */

it('asks every page to poll every 30 seconds while the competition runs and not otherwise', function (CompetitionStatus $status, ?int $poll): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    // Drawn while signup is closed (the desk's rule), then moved to the
    // state under test.
    [$competition, [$a], , $stage] = publicPagesBracket($eventId);
    $competition->forceFill(['status' => $status])->save();
    $member = publicPagesMember($a);

    $urls = [
        '/events/sxlan-27/tournaments',
        publicPagesUrl($competition),
        publicPagesUrl($competition, '/matches/'.competitionMatchAt($stage, 1, 1)->id),
        publicPagesUrl($competition, '/teams/'.$a->competition_team_id),
    ];

    foreach ($urls as $url) {
        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('page.pollSeconds', $poll));

        $this->app->forgetScopedInstances();
    }

    // "Meine Turniere" is a participant page and follows the same rule.
    $this->actingAs($member)
        ->get('/my/tournaments')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pollSeconds', $poll));
})->with([
    'running' => [CompetitionStatus::Running, 30],
    'signup closed' => [CompetitionStatus::SignupClosed, null],
    'published' => [CompetitionStatus::Published, null],
    'finished' => [CompetitionStatus::Finished, null],
]);

/*
 * ---------------------------------------------------------------------------
 * The participant writes: gated, throttled, and only for one's own side
 * ---------------------------------------------------------------------------
 */

it('puts every participant write behind auth and the upload\'s throttle', function (): void {
    $names = [
        'competition.entries.store',
        'competition.score-entries.store',
        'competition.matches.report',
        'competition.matches.confirm',
        'competition.matches.dispute',
    ];

    foreach ($names as $name) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

        expect($middleware)->toContain('auth')
            ->and($middleware)->toContain('throttle:6,1')
            ->and($middleware)->toContain('ensure-module:competition');
    }
});

it('sends a guest to the login for every participant write', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, , , $stage] = publicPagesBracket($eventId);
    $match = competitionMatchAt($stage, 1, 1)->id;
    $base = '/tournaments/'.$competition->id;

    foreach (['/entries', '/score-entries', "/matches/{$match}/report", "/matches/{$match}/confirm", "/matches/{$match}/dispute"] as $path) {
        $this->post($base.$path)->assertRedirect(route('login'));
    }
});

it('lets a member of a side report, the other side confirm, and nobody else speak for either', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a, , , $d], , $stage] = publicPagesBracket($eventId);
    $match = competitionMatchAt($stage, 1, 1);
    $url = '/tournaments/'.$competition->id.'/matches/'.$match->id;

    $ofA = publicPagesMember($a);
    $ofD = publicPagesMember($d);
    $outsider = User::factory()->create();

    // Somebody on neither side: not a 403, a 404 — there is no side of theirs.
    $this->actingAs($outsider)->post($url.'/report', ['scores' => [1 => 16, 2 => 9]])->assertNotFound();

    // $a is slot 1 of match 1 (S(4)): 16 to 9 for $a.
    $this->actingAs($ofA)->post($url.'/report', ['scores' => [1 => 16, 2 => 9]])->assertSessionHasNoErrors();

    expect($match->fresh()->reported_at)->not->toBeNull()
        ->and($match->fresh()->state)->toBe(MatchState::Pending);

    // The other side sees the claim on the match page and may confirm it.
    $this->actingAs($ofD)
        ->get(publicPagesUrl($competition, '/matches/'.$match->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.position', 2)
            ->where('page.viewer.opposingReport', [1 => 16, 2 => 9])
            ->where('page.viewer.canConfirm', true)
            ->has('page.history', 1)
            ->where('page.history.0.kind', 'reported'));

    // …and it waits for them in "Meine Turniere".
    $this->actingAs($ofD)
        ->get('/my/tournaments')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('pendingConfirmations', 1)
            ->where('pendingConfirmations.0.matchId', $match->id)
            ->where('pendingConfirmations.0.eventSlug', 'sxlan-27')
            ->where('pendingConfirmations.0.scores', [1 => 16, 2 => 9]));

    // The reporting side cannot confirm its own claim.
    $this->actingAs($ofA)->post($url.'/confirm')->assertSessionHasErrors('result');

    $this->actingAs($ofD)->post($url.'/confirm')->assertSessionHasNoErrors();

    expect($match->fresh()->state)->toBe(MatchState::Finished)
        ->and($match->fresh()->winner_entrant_id)->toBe($a->id);
});

it('shows the history only to the sides of a match', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a], $members, $stage] = publicPagesBracket($eventId);
    $match = competitionMatchAt($stage, 1, 1);

    app(ReportMatchResult::class)->handle($match, $a, new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$a->id]);

    // A guest sees the match, not its timeline (§8.6: "to the sides and the desk").
    $this->get(publicPagesUrl($competition, '/matches/'.$match->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.position', null)
            ->where('page.history', [])
            ->where('page.viewer.canReport', false));
});

it('offers no report on a match whose other side is still to come', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a], , $stage] = publicPagesBracket($eventId);
    $ofA = publicPagesMember($a);

    // $a beats $d; the final has $a in slot 1 and waits on $b v $c.
    app(RecordResultAtDesk::class)->handle(competitionMatchAt($stage, 1, 1), [1 => 2, 2 => 0], $a->id, (string) Str::ulid());
    $final = competitionMatchAt($stage, 2, 1);

    $this->actingAs($ofA)
        ->get(publicPagesUrl($competition, '/matches/'.$final->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.position', 1)
            ->where('page.viewer.canReport', false)
            ->where('page.match.sides.1.awaiting', true));

    // And the door refuses it, not only the page (`ReportMatchResult`).
    $this->actingAs($ofA)
        ->post('/tournaments/'.$competition->id.'/matches/'.$final->id.'/report', ['scores' => [1 => 1]])
        ->assertSessionHasErrors('scores');

    expect(MatchResultReport::query()->where('competition_match_id', $final->id)->count())->toBe(0);
});

it('files a participant dispute where the desk sees it', function (): void {
    // Carry ruling 3: no participant-side dispute path the desk cannot see.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a, , , $d], $members, $stage] = publicPagesBracket($eventId);
    $match = competitionMatchAt($stage, 1, 1);
    $ofD = publicPagesMember($d);

    app(ReportMatchResult::class)->handle($match, $a, new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$a->id]);

    $this->actingAs($ofD)
        ->post('/tournaments/'.$competition->id.'/matches/'.$match->id.'/dispute', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($ofD)
        ->post('/tournaments/'.$competition->id.'/matches/'.$match->id.'/dispute', ['reason' => 'Wir haben 16:12 gewonnen.'])
        ->assertSessionHasNoErrors();

    $desk = app(TournamentDesk::class)->for($competition->fresh());

    expect($match->fresh()->isDisputed())->toBeTrue()
        ->and($desk->disputes)->toHaveCount(1)
        ->and($desk->disputes[0]->matchId)->toBe($match->id)
        ->and($desk->disputes[0]->reason)->toBe('Wir haben 16:12 gewonnen.');
});

it('lets a captain enter their team and nobody enter a team they do not captain', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    // Two seats, and two people on the team: the entry is complete.
    $competition = Competition::factory()->forEvent($eventId)->create([
        'slug' => 'main-cup',
        'discipline_id' => Discipline::factory()->teamSize(2)->create()->id,
    ]);
    $team = CompetitionTeam::factory()->forEvent($eventId)->create(['name' => 'Die Kellerkinder']);

    $captain = User::factory()->create();
    $member = User::factory()->create();

    foreach ([[$captain, TeamRole::Captain], [$member, TeamRole::Member]] as [$user, $role]) {
        $row = new CompetitionTeamMember([
            'competition_team_id' => $team->id,
            'user_id' => (string) $user->getKey(),
            'role' => $role,
        ]);
        $row->forceFill(['brand_id' => $team->brand_id])->save();
    }

    // The captain sees the team among those they could enter…
    competitionEligibleUser($eventId, (string) $brand->getKey(), (string) $captain->getKey());

    $this->actingAs($captain)
        ->get(publicPagesUrl($competition))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.eligible', true)
            ->where('page.viewer.enterableTeams.0.id', $team->id)
            ->where('page.viewer.enterableTeams.0.members', 2));

    // …a member who is not captain may not enter it: a 404, not a 403.
    $this->actingAs($member)
        ->post('/tournaments/'.$competition->id.'/entries', ['teamId' => $team->id])
        ->assertNotFound();

    $this->actingAs($captain)
        ->post('/tournaments/'.$competition->id.'/entries', ['teamId' => $team->id])
        ->assertSessionHasNoErrors();

    expect(Entrant::query()->where('competition_id', $competition->id)->where('competition_team_id', $team->id)->exists())->toBeTrue();
});

it('takes a leaderboard score from a member of the entry and from nobody else', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a]] = competitionFieldWithRosters(2, [
        'event_id' => $eventId,
        'slug' => 'trackmania',
        'status' => CompetitionStatus::Running,
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);
    Stage::factory()->leaderboard(new LeaderboardSettings(heats: null, unit: 's'))->create(['competition_id' => $competition->id]);

    $ofA = publicPagesMember($a);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post('/tournaments/'.$competition->id.'/score-entries', ['value' => 61.5])
        ->assertNotFound();

    $this->actingAs($ofA)
        ->get(publicPagesUrl($competition))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page.viewer.canSubmitScore', true));

    $this->actingAs($ofA)
        ->post('/tournaments/'.$competition->id.'/score-entries', ['value' => 61.5])
        ->assertSessionHasNoErrors();

    $entry = ScoreEntry::query()->where('entrant_id', $a->id)->sole();

    // Unverified until the desk looks at it (§8.3), and listed as such.
    expect($entry->verified_at)->toBeNull()
        ->and((float) $entry->value)->toBe(61.5);

    $this->actingAs($ofA)
        ->get('/my/tournaments')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('scoreEntries.0.state', 'pending')
            ->where('scoreEntries.0.value', 61.5));
});

/*
 * ---------------------------------------------------------------------------
 * No proof upload anywhere (carry ruling 4, #322)
 * ---------------------------------------------------------------------------
 */

it('says a required proof is collected at the desk instead of offering a report', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a], , $stage] = publicPagesBracket($eventId, ['proof_policy' => ProofPolicy::Required]);
    $ofA = publicPagesMember($a);

    $this->actingAs($ofA)
        ->get(publicPagesUrl($competition, '/matches/'.competitionMatchAt($stage, 1, 1)->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.competition.proofRequired', true)
            ->where('page.viewer.position', 1)
            ->where('page.viewer.canReport', false));
});

it('ships no upload control on any competition screen', function (): void {
    // The upload route stays (it is live ahead of #322), but until Platform
    // can say who may read a proof, no screen of this module offers it: no
    // file input, and no reference to the proof upload route.
    $files = collect(File::allFiles(dirname(__DIR__).'/resources/js'))
        ->filter(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['vue', 'ts'], true))
        ->reject(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.test.ts'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getPathname());

        expect($source)
            ->not->toMatch('/type=["\']file["\']/')
            ->not->toContain('routes/competition/proofs')
            ->not->toContain('/proofs');
    }
});

/*
 * ---------------------------------------------------------------------------
 * Names come from the read model, and the index counts slot holders (§10)
 * ---------------------------------------------------------------------------
 */

it('shows rosters and the interested list with names from the participant profiles', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition, [$a], $members] = publicPagesBracket($eventId);
    competitionParticipantName($members[$a->id], 'Anna Beispiel', 'anna');

    $looking = competitionEligibleUser($eventId, (string) $brand->getKey());
    competitionParticipantName($looking, 'Bernd Suchend', 'bernd');

    app(ExpressInterest::class)->handle($competition, $looking, 'Spiele Support');

    $this->get(publicPagesUrl($competition))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('page.teams', 4)
            ->where('page.teams.0.teamId', $a->competition_team_id)
            ->where('page.teams.0.members', ['Anna Beispiel'])
            ->where('page.interested', [['displayName' => 'Bernd Suchend', 'note' => 'Spiele Support']])
            ->where('page.competition.entrants', 4));
});

it('keeps an unknown match of this competition a 404 rather than an error', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = publicPagesEvent($brand);

    [$competition] = publicPagesBracket($eventId);

    $this->get(publicPagesUrl($competition, '/matches/'.Str::ulid()))->assertNotFound();
    $this->get(publicPagesUrl($competition, '/teams/'.Str::ulid()))->assertNotFound();

    expect(CompetitionMatch::query()->where('competition_id', $competition->id)->count())->toBe(3);
});
