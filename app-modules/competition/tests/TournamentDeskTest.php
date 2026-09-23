<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Actions\DisputeMatchResult;
use Konventix\Competition\Actions\ReportMatchResult;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\LeaderboardDirection;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Identity\Models\User;

/**
 * The tournament desk (design §9, D-CMP-20, D-CMP-21) on the operator host:
 * seed order, generate and reset, per-round and per-match scheduling, the
 * result dialogs, the open disputes, a leaderboard's verification queue, and
 * the console's person search for the roster editor (§5.2).
 *
 * Every row is created **before** the first admin-host request, and every
 * read after one escapes `BrandScope`: such a request resolves no brand, and
 * the scope hardens brand-owned queries to empty (.ai/rules/tests.md). That
 * is why this file reads matches and slots through its own
 * `withoutBrandScope()` helpers rather than the shared `competitionMatchAt()`
 * and `competitionSidesOf()`.
 *
 * Expected values are derived from the code by hand; the derivations sit
 * beside the assertions. The one construction most of them lean on is
 * `SingleEliminationGenerator::seedOrder()`: S(4) = [1, 4, 2, 3] and
 * S(8) = [1, 8, 4, 5, 2, 7, 3, 6], read two at a time as round one, and
 * matches `2p-1` and `2p` of a round feed slots 1 and 2 of match `p` of the
 * next.
 */
function deskTestUrl(string $path): string
{
    return 'http://admin.tournaments.test/admin'.$path;
}

/**
 * A console account on the installation's operator. By default it holds the
 * view capability and all four of §9's desk capabilities.
 *
 * @param  list<string>|null  $permissions
 */
function deskTestAdmin(?array $permissions = null): User
{
    [, $admin] = competitionOperator($permissions ?? [
        'platform.access-admin',
        'competition.view-competitions',
        'competition.check-in-entrants',
        'competition.record-results',
        'competition.correct-results',
        'competition.manage-schedule',
    ]);

    return $admin;
}

function deskTestMatch(Stage $stage, int $round, int $position): CompetitionMatch
{
    /** @var CompetitionMatch */
    return CompetitionMatch::withoutBrandScope()
        ->where('stage_id', $stage->getKey())
        ->where('round', $round)
        ->where('position', $position)
        ->firstOrFail();
}

/**
 * @return array<int, string|null> slot position => entrant ULID
 */
function deskTestSides(CompetitionMatch $match): array
{
    return MatchSlot::withoutBrandScope()
        ->where('competition_match_id', $match->getKey())
        ->orderBy('position')
        ->pluck('entrant_id', 'position')
        ->all();
}

function deskTestMatchCount(Stage $stage): int
{
    return CompetitionMatch::withoutBrandScope()->where('stage_id', $stage->getKey())->count();
}

function deskTestSeedOf(Entrant $entrant): ?int
{
    return Entrant::withoutBrandScope()->whereKey($entrant->getKey())->value('seed');
}

function deskTestHistoryCount(CompetitionMatch $match, MatchHistoryKind $kind): int
{
    return MatchHistoryEntry::withoutBrandScope()
        ->where('competition_match_id', $match->getKey())
        ->where('kind', $kind->value)
        ->count();
}

/*
 * ---------------------------------------------------------------------------
 * Capabilities (§9, §11)
 * ---------------------------------------------------------------------------
 */

it('403s the desk page and every desk write without a desk capability', function (): void {
    // Fourteen endpoints. Each opens with its capability check, before any
    // lookup, so random ULIDs are enough — and a method that forgot the line
    // would be open to any console account with nothing else noticing.
    $admin = deskTestAdmin(['platform.access-admin', 'competition.view-competitions']);

    $id = (string) Str::ulid();

    $endpoints = [
        ['get', "/competitions/{$id}/desk"],
        ['post', "/competition-stages/{$id}/seed"],
        ['post', "/competition-stages/{$id}/shuffle"],
        ['post', "/competition-stages/{$id}/generate"],
        ['post', "/competition-stages/{$id}/regenerate"],
        ['post', "/competition-stages/{$id}/reset"],
        ['post', "/competition-stages/{$id}/schedule-round"],
        ['put', "/competition-matches/{$id}/schedule"],
        ['post', "/competition-matches/{$id}/record"],
        ['post', "/competition-matches/{$id}/correct"],
        ['post', "/competition-matches/{$id}/resolve"],
        ['post', "/competition-stages/{$id}/score-entries"],
        ['post', "/competition-score-entries/{$id}/verify"],
        ['post', "/competition-score-entries/{$id}/reject"],
    ];

    expect($endpoints)->toHaveCount(14);

    foreach ($endpoints as [$method, $path]) {
        $this->actingAs($admin)->{$method}(deskTestUrl($path))->assertForbidden();
    }
});

it('keeps correcting away from the Tournament Desk role', function (): void {
    // §11's Tournament Desk: view, check-in, record, schedule — and not
    // `CorrectResults`, one of the admin's two operations with a blast
    // radius (#135). The page itself opens for it.
    $this->withBrand();
    $competition = Competition::factory()->create();
    Stage::factory()->create(['competition_id' => $competition->getKey()]);

    $desk = deskTestAdmin([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.check-in-entrants',
        'competition.record-results',
        'competition.manage-schedule',
    ]);

    $this->actingAs($desk)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/desk/Desk')
            ->where('desk.powers.recordResults', true)
            ->where('desk.powers.manageSchedule', true)
            ->where('desk.powers.correctResults', false));

    $this->actingAs($desk)
        ->post(deskTestUrl('/competition-matches/'.Str::ulid().'/correct'))
        ->assertForbidden();
});

it('keeps the draw and the clock to ManageSchedule', function (): void {
    // Somebody who may record results but not arrange the tournament: §9
    // puts the seed order, the draw and the times behind `ManageSchedule`.
    $recorder = deskTestAdmin([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.record-results',
    ]);

    $id = (string) Str::ulid();

    foreach (['seed', 'shuffle', 'generate', 'regenerate', 'reset', 'schedule-round'] as $action) {
        $this->actingAs($recorder)
            ->post(deskTestUrl("/competition-stages/{$id}/{$action}"))
            ->assertForbidden();
    }

    $this->actingAs($recorder)
        ->put(deskTestUrl("/competition-matches/{$id}/schedule"))
        ->assertForbidden();
});

/*
 * ---------------------------------------------------------------------------
 * Seed order, generate, regenerate, reset (§7.5, D-CMP-20)
 * ---------------------------------------------------------------------------
 */

it('sets a seed order at the desk and draws the bracket again from it', function (): void {
    $this->withBrand();

    // Four entrants seeded 1..4 in creation order: $a = 1, $b = 2, $c = 3,
    // $d = 4 (`competitionFieldWithRosters()`).
    [$competition, [$a, $b, $c, $d]] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    $admin = deskTestAdmin();

    // Before: S(4) = [1, 4, 2, 3] → match 1 is seed 1 v seed 4 = $a v $d.
    expect(deskTestSides(deskTestMatch($stage, 1, 1)))->toBe([1 => $a->id, 2 => $d->id]);

    // The desk drags the field upside down: $d, $c, $b, $a become seeds 1..4.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/seed'), [
            'entrantIds' => [$d->id, $c->id, $b->id, $a->id],
        ])
        ->assertSessionHasNoErrors();

    expect([deskTestSeedOf($d), deskTestSeedOf($c), deskTestSeedOf($b), deskTestSeedOf($a)])->toBe([1, 2, 3, 4]);

    // Seeding alone does not touch a drawn bracket (`SeedStage` writes
    // `entrants.seed` only); drawing again does.
    expect(deskTestSides(deskTestMatch($stage, 1, 1)))->toBe([1 => $a->id, 2 => $d->id]);

    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/regenerate'))
        ->assertSessionHasNoErrors();

    // After: S(4) = [1, 4, 2, 3] over the new seeds.
    //   match 1: seed 1 v seed 4 = $d v $a
    //   match 2: seed 2 v seed 3 = $c v $b
    // Four entrants fill a bracket of four: two first-round matches and a
    // final, three in all.
    expect(deskTestSides(deskTestMatch($stage, 1, 1)))->toBe([1 => $d->id, 2 => $a->id])
        ->and(deskTestSides(deskTestMatch($stage, 1, 2)))->toBe([1 => $c->id, 2 => $b->id])
        ->and(deskTestMatchCount($stage))->toBe(3);

    // A partial drag: only $b is named. `ManualSeedSource` puts it first and
    // appends the rest in the field's current order — read before the old
    // seeds are cleared, so by seed: $d (1), $c (2), $a (4), $b skipped.
    //   → $b = 1, $d = 2, $c = 3, $a = 4
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/seed'), ['entrantIds' => [$b->id]])
        ->assertSessionHasNoErrors();

    expect([deskTestSeedOf($b), deskTestSeedOf($d), deskTestSeedOf($c), deskTestSeedOf($a)])->toBe([1, 2, 3, 4]);

    // The desk page lists the field in that order — `SignupField`'s, the
    // one the bracket is drawn from.
    $this->actingAs($admin)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('desk.field.0.id', $b->id)
            ->where('desk.field.1.id', $d->id)
            ->where('desk.field.2.id', $c->id)
            ->where('desk.field.3.id', $a->id)
            ->where('desk.stage.generated', true)
            ->where('desk.stage.hasResult', false)
            ->has('desk.matches', 3));

    // A stale screen naming somebody who is not in the field is refused
    // rather than silently dropped, and nothing moves.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/seed'), ['entrantIds' => [(string) Str::ulid()]])
        ->assertSessionHasErrors('desk');

    expect(deskTestSeedOf($b))->toBe(1);
});

it('generates and resets a stage, and refuses both once a result is recorded', function (): void {
    $this->withBrand();

    [$competition, [$a, , , $d]] = competitionFieldWithRosters(4);
    $stage = Stage::factory()->create(['competition_id' => $competition->getKey()]);

    $admin = deskTestAdmin();
    $url = fn (string $action): string => deskTestUrl('/competition-stages/'.$stage->getKey().'/'.$action);

    $this->actingAs($admin)->post($url('generate'))->assertSessionHasNoErrors();

    // Four entrants: bracketSize(4) = 4, so 2 + 1 = 3 matches.
    expect($stage->refresh()->generated_at)->not->toBeNull()
        ->and(deskTestMatchCount($stage))->toBe(3);

    // Generating twice is refused, not a silent truncate.
    $this->actingAs($admin)->post($url('generate'))->assertSessionHasErrors('desk');
    expect(deskTestMatchCount($stage))->toBe(3);

    $this->actingAs($admin)->post($url('reset'))->assertSessionHasNoErrors();

    expect($stage->refresh()->generated_at)->toBeNull()
        ->and(deskTestMatchCount($stage))->toBe(0)
        ->and(MatchSlot::withoutBrandScope()->where('brand_id', $stage->brand_id)->count())->toBe(0)
        // Seeds survive a reset.
        ->and(deskTestSeedOf($a))->toBe(1);

    $this->actingAs($admin)->post($url('generate'))->assertSessionHasNoErrors();
    expect(deskTestMatchCount($stage))->toBe(3);

    // A result is recorded through the desk's own dialog: match 1 is
    // $a v $d (S(4), seeds 1 and 4).
    $first = deskTestMatch($stage, 1, 1);

    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$first->getKey().'/record'), [
            'scores' => [1 => 2, 2 => 0],
            'winnerEntrantId' => $a->id,
        ])
        ->assertSessionHasNoErrors();

    // From here the draw is frozen (D-CMP-20): reset, redraw and re-seed are
    // all refused as a desk error, and the bracket stands.
    foreach (['reset', 'regenerate', 'shuffle'] as $action) {
        $this->actingAs($admin)->post($url($action))->assertSessionHasErrors('desk');
    }

    expect(deskTestMatchCount($stage))->toBe(3)
        ->and(deskTestMatch($stage, 1, 1)->state)->toBe(MatchState::Finished)
        ->and(deskTestSeedOf($d))->toBe(4);
});

/*
 * ---------------------------------------------------------------------------
 * Scheduling (D-CMP-21)
 * ---------------------------------------------------------------------------
 */

it('schedules a whole round at one instant and leaves decided matches and other rounds alone', function (): void {
    $this->withBrand();

    // Eight entrants: bracketSize(8) = 8, so round 1 has 4 matches, round 2
    // has 2 and the final 1.
    [$competition, [$a, , , , , , , $h]] = competitionFieldWithRosters(8);
    $stage = competitionGeneratedStage($competition);

    $admin = deskTestAdmin();

    // Match 1 of round 1 is S(8)'s first pair, seed 1 v seed 8 = $a v $h. It
    // is decided before the round is scheduled.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.deskTestMatch($stage, 1, 1)->getKey().'/record'), [
            'scores' => [1 => 1, 2 => 0],
            'winnerEntrantId' => $a->id,
        ])
        ->assertSessionHasNoErrors();

    // 12:00 in UTC+2 is 10:00 UTC — the page sends an instant with its
    // offset, `ScheduleRoundData::scheduledAtInstant()` normalises it.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/schedule-round'), [
            'round' => 1,
            'bracket' => null,
            'scheduledAt' => '2026-10-24T12:00:00+02:00',
        ])
        ->assertSessionHasNoErrors();

    // Matches 2, 3 and 4 of round 1 move; match 1 was finished and keeps no
    // time; round 2 and the final are untouched.
    foreach ([2, 3, 4] as $position) {
        $match = deskTestMatch($stage, 1, $position);

        expect($match->state)->toBe(MatchState::Scheduled)
            ->and($match->scheduled_at?->utc()->toIso8601String())->toBe('2026-10-24T10:00:00+00:00')
            ->and(deskTestHistoryCount($match, MatchHistoryKind::Scheduled))->toBe(1);
    }

    expect(deskTestMatch($stage, 1, 1)->state)->toBe(MatchState::Finished)
        ->and(deskTestMatch($stage, 1, 1)->scheduled_at)->toBeNull()
        ->and(deskTestMatch($stage, 2, 1)->scheduled_at)->toBeNull()
        ->and(deskTestMatch($stage, 2, 2)->scheduled_at)->toBeNull()
        ->and(deskTestMatch($stage, 3, 1)->scheduled_at)->toBeNull();

    // The same instant again is no news: no `rescheduled` row appears.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/schedule-round'), [
            'round' => 1,
            'scheduledAt' => '2026-10-24T10:00:00+00:00',
        ])
        ->assertSessionHasNoErrors();

    expect(deskTestHistoryCount(deskTestMatch($stage, 1, 2), MatchHistoryKind::Rescheduled))->toBe(0);

    // One match of the round moved on its own: 12:30 in UTC+2 is 10:30 UTC,
    // and it is news — a `rescheduled` row.
    $moved = deskTestMatch($stage, 1, 3);

    $this->actingAs($admin)
        ->put(deskTestUrl('/competition-matches/'.$moved->getKey().'/schedule'), [
            'scheduledAt' => '2026-10-24T12:30:00+02:00',
        ])
        ->assertSessionHasNoErrors();

    expect(deskTestMatch($stage, 1, 3)->scheduled_at?->utc()->toIso8601String())->toBe('2026-10-24T10:30:00+00:00')
        ->and(deskTestHistoryCount($moved, MatchHistoryKind::Rescheduled))->toBe(1)
        ->and(deskTestMatch($stage, 1, 2)->scheduled_at?->utc()->toIso8601String())->toBe('2026-10-24T10:00:00+00:00');

    // A decided match cannot be given a time.
    $this->actingAs($admin)
        ->put(deskTestUrl('/competition-matches/'.deskTestMatch($stage, 1, 1)->getKey().'/schedule'), [
            'scheduledAt' => '2026-10-24T12:30:00+02:00',
        ])
        ->assertSessionHasErrors('desk');
});

/*
 * ---------------------------------------------------------------------------
 * Record and correct (§8.2 step 3, §8.5)
 * ---------------------------------------------------------------------------
 */

it('records a result at the desk, advances the winner, and corrects it', function (): void {
    $this->withBrand();

    [$competition, [$a, , , $d]] = competitionFieldWithRosters(4);
    $stage = competitionGeneratedStage($competition);

    $admin = deskTestAdmin();
    $first = deskTestMatch($stage, 1, 1);
    $final = deskTestMatch($stage, 2, 1);

    // Match 1 is $a (slot 1) v $d (slot 2). Its winner feeds slot 1 of the
    // final: position 1 is odd, so `targetSlot` is 1.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$first->getKey().'/record'), [
            'scores' => [1 => 13, 2 => 7],
            'winnerEntrantId' => $a->id,
        ])
        ->assertSessionHasNoErrors();

    expect(deskTestMatch($stage, 1, 1)->state)->toBe(MatchState::Finished)
        ->and(deskTestMatch($stage, 1, 1)->winner_entrant_id)->toBe($a->id)
        ->and(deskTestSides($final)[1])->toBe($a->id);

    // Recording over a finished match is a correction, not a second record.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$first->getKey().'/record'), [
            'scores' => [1 => 0, 2 => 1],
            'winnerEntrantId' => $d->id,
        ])
        ->assertSessionHasErrors('desk');

    // The correction keys the scores by entrant. The final has no result
    // yet, so nothing downstream is voided and no note is owed; $d replaces
    // $a in the final's slot 1.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$first->getKey().'/correct'), [
            'scores' => [$a->id => 7, $d->id => 13],
            'winnerEntrantId' => $d->id,
        ])
        ->assertSessionHasNoErrors();

    expect(deskTestMatch($stage, 1, 1)->winner_entrant_id)->toBe($d->id)
        ->and(deskTestSides(deskTestMatch($stage, 1, 1)))->toBe([1 => $a->id, 2 => $d->id])
        ->and(MatchSlot::withoutBrandScope()->where('competition_match_id', $first->getKey())->orderBy('position')->pluck('score')->all())->toBe([7, 13])
        ->and(deskTestSides($final)[1])->toBe($d->id);

    // A winner who is not a side of the match is refused.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.deskTestMatch($stage, 1, 2)->getKey().'/record'), [
            'scores' => [1 => 1, 2 => 0],
            'winnerEntrantId' => $a->id,
        ])
        ->assertSessionHasErrors('desk');
});

/*
 * ---------------------------------------------------------------------------
 * Disputes (§8.4)
 * ---------------------------------------------------------------------------
 */

it('shows the desk every open dispute with both claims and resolves it with a note', function (): void {
    $this->withBrand();

    [$competition, [$a, $b], $members] = competitionFieldWithRosters(2, [
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);
    $stage = competitionGeneratedStage($competition);

    // Two entrants: bracketSize(2) = 2, S(2) = [1, 2] — one match, $a in
    // slot 1 and $b in slot 2.
    $match = deskTestMatch($stage, 1, 1);

    // The two sides disagree: each claims the win. The second report differs
    // from the first, so `ReportMatchResult` hands the match to
    // `DisputeMatchResult` with its own reason.
    app(ReportMatchResult::class)->handle($match, $a, new MatchReportData(scores: [1 => 16, 2 => 9]), $members[$a->id]);
    app(ReportMatchResult::class)->handle($match->refresh(), $b, new MatchReportData(scores: [1 => 9, 2 => 16]), $members[$b->id]);

    $admin = deskTestAdmin();

    $this->actingAs($admin)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/desk/Desk')
            ->has('desk.disputes', 1)
            ->where('desk.disputes.0.matchId', $match->getKey())
            ->where('desk.disputes.0.reason', 'The two sides reported different results.')
            ->where('desk.disputes.0.recorded', false)
            ->has('desk.disputes.0.reports', 2)
            ->where('desk.disputes.0.reports.0.entrantId', $a->id)
            ->where('desk.disputes.0.reports.0.scores.1', 16)
            ->where('desk.disputes.0.reports.1.entrantId', $b->id)
            ->where('desk.disputes.0.reports.1.scores.1', 9)
            ->where('desk.matches.0.disputed', true));

    // The desk cannot record over a dispute: both sides are owed a reason.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$match->getKey().'/record'), [
            'scores' => [1 => 16, 2 => 9],
            'winnerEntrantId' => $a->id,
        ])
        ->assertSessionHasErrors('desk');

    // A resolution without a note is refused by `ResolveDispute`.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$match->getKey().'/resolve'), [
            'scores' => [1 => 16, 2 => 9],
            'winnerEntrantId' => $a->id,
        ])
        ->assertSessionHasErrors('resolutionNote');

    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-matches/'.$match->getKey().'/resolve'), [
            'scores' => [1 => 16, 2 => 9],
            'winnerEntrantId' => $a->id,
            'note' => 'The screenshot shows 16:9.',
        ])
        ->assertSessionHasNoErrors();

    $resolved = deskTestMatch($stage, 1, 1);

    expect($resolved->state)->toBe(MatchState::Finished)
        ->and($resolved->winner_entrant_id)->toBe($a->id)
        ->and($resolved->dispute_resolved_at)->not->toBeNull()
        ->and(MatchHistoryEntry::withoutBrandScope()
            ->where('competition_match_id', $match->getKey())
            ->where('kind', MatchHistoryKind::Resolved->value)
            ->sole()
            ->payloadData()->note)->toBe('The screenshot shows 16:9.');

    $this->actingAs($admin)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('desk.disputes', 0)
            ->where('desk.matches.0.disputed', false));
});

it('asks CorrectResults to resolve a dispute raised against a standing result', function (): void {
    // §8.4: "`RecordResults` before a result exists, `CorrectResults`
    // after". A desk volunteer without the second may not settle it.
    $this->withBrand();

    [$competition, [$a, $b], $members] = competitionFieldWithRosters(2, [
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);
    $stage = competitionGeneratedStage($competition);
    $match = deskTestMatch($stage, 1, 1);

    // Both sides agree (a counter-report that agrees is a confirmation,
    // §14), then the loser contests the recorded result.
    app(ReportMatchResult::class)->handle($match, $a, new MatchReportData(scores: [1 => 3, 2 => 1]), $members[$a->id]);
    app(ReportMatchResult::class)->handle($match->refresh(), $b, new MatchReportData(scores: [1 => 3, 2 => 1]), $members[$b->id]);
    app(DisputeMatchResult::class)
        ->handle($match->refresh(), $b, 'They used a banned map.', $members[$b->id]);

    $desk = deskTestAdmin([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.record-results',
    ]);

    $this->actingAs($desk)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('desk.disputes.0.recorded', true)
            ->where('desk.disputes.0.reason', 'They used a banned map.'));

    $this->actingAs($desk)
        ->post(deskTestUrl('/competition-matches/'.$match->getKey().'/resolve'), [
            'scores' => [1 => 3, 2 => 1],
            'winnerEntrantId' => $a->id,
            'note' => 'The map was allowed.',
        ])
        ->assertForbidden();

    expect(deskTestMatch($stage, 1, 1)->dispute_resolved_at)->toBeNull();
});

/*
 * ---------------------------------------------------------------------------
 * A leaderboard's verification queue (§8.3)
 * ---------------------------------------------------------------------------
 */

it('verifies and rejects scores in the queue and enters the desk\'s own', function (): void {
    $this->withBrand();

    [$competition, [$a, $b], $members] = competitionFieldWithRosters(2, [
        'result_submission_mode' => ResultSubmissionMode::Participants,
    ]);

    // Trackmania: one best time per entrant, lower is better (§7.2).
    $stage = Stage::factory()
        ->leaderboard(new LeaderboardSettings(direction: LeaderboardDirection::Lower, unit: 's', decimals: 2))
        ->create(['competition_id' => $competition->getKey()]);

    $submit = app(SubmitScoreEntry::class);
    $fast = $submit->handle($stage, $a, new ScoreEntryData(value: 61.5), $members[$a->id]);
    $slow = $submit->handle($stage, $b, new ScoreEntryData(value: 70.0), $members[$b->id]);

    $admin = deskTestAdmin();

    $this->actingAs($admin)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('desk.stage.isLeaderboard', true)
            ->where('desk.stage.unit', 's')
            ->has('desk.queue', 2)
            ->where('desk.queue.0.id', $fast->id)
            ->has('desk.standings', 0));

    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-score-entries/'.$fast->id.'/verify'))
        ->assertSessionHasNoErrors();

    // Rejecting needs the reason the participant is shown.
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-score-entries/'.$slow->id.'/reject'), ['rejectionNote' => ''])
        ->assertSessionHasErrors('rejectionNote');

    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-score-entries/'.$slow->id.'/reject'), [
            'rejectionNote' => 'The replay shows a shortcut.',
        ])
        ->assertSessionHasNoErrors();

    expect(ScoreEntry::withoutBrandScope()->findOrFail($fast->id)->isVerified())->toBeTrue()
        ->and(ScoreEntry::withoutBrandScope()->findOrFail($slow->id)->rejection_note)->toBe('The replay shows a shortcut.');

    // The desk types $b's corrected time itself: verified on creation, and it
    // replaces the rejected row (one row per stage, entrant and heat).
    $this->actingAs($admin)
        ->post(deskTestUrl('/competition-stages/'.$stage->getKey().'/score-entries'), [
            'entrantId' => $b->id,
            'value' => 65.25,
        ])
        ->assertSessionHasNoErrors();

    $entries = ScoreEntry::withoutBrandScope()->where('stage_id', $stage->getKey())->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->every(fn (ScoreEntry $entry): bool => $entry->isVerified()))->toBeTrue();

    // Lower is better: $a's 61.5 ranks first, $b's 65.25 second, and the
    // aggregate (`best` of one entry) is the entry itself.
    $this->actingAs($admin)
        ->get(deskTestUrl('/competitions/'.$competition->getKey().'/desk'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('desk.queue', 0)
            ->has('desk.standings', 2)
            ->where('desk.standings.0.entrantId', $a->id)
            ->where('desk.standings.0.placement', 1)
            ->where('desk.standings.0.tiebreaker', 61.5)
            ->where('desk.standings.1.entrantId', $b->id)
            ->where('desk.standings.1.placement', 2)
            ->where('desk.standings.1.tiebreaker', 65.25));

    expect(Standing::withoutBrandScope()->where('stage_id', $stage->getKey())->count())->toBe(2);
});

/*
 * ---------------------------------------------------------------------------
 * The roster editor's person search (§5.2)
 * ---------------------------------------------------------------------------
 */

it('finds people with a confirmed registration for the roster editor, and nobody else', function (): void {
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);

    $competition = Competition::factory()->forEvent($eventId)->create();

    $eligible = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($eligible, 'Anna Beispiel', 'anna');

    // A profile of somebody without a registration for this event.
    competitionParticipantName((string) Str::ulid(), 'Anna Fremd', 'annaf');

    $admin = deskTestAdmin([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.manage-entrants',
    ]);

    $url = deskTestUrl('/competitions/'.$competition->getKey().'/entrants?search=ann');

    $first = $this->actingAs($admin)->get($url);

    // Not sent on the first visit: it is an optional prop.
    $first->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/Entrants')
            ->where('canManage', true)
            ->missing('people'));

    $people = $this->actingAs($admin)->get($url, [
        Header::INERTIA => 'true',
        Header::VERSION => (string) $first->viewData('page')['version'],
        Header::PARTIAL_COMPONENT => 'admin/competitions/Entrants',
        Header::PARTIAL_ONLY => 'people',
    ]);

    $people->assertOk();

    expect($people->json('props.people'))->toBe([
        ['userId' => $eligible, 'displayName' => 'Anna Beispiel', 'username' => 'anna'],
    ]);
});
