<?php

declare(strict_types=1);

use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\PairNextSwissRound;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Standings\StandingsCalculatorRegistry;

/**
 * The ranking rules are proved in `StandingsMathematicsTest` without a
 * database. This covers persistence: that a recompute writes the table it
 * calculated, replaces rather than merges, and that Swiss can pair a second
 * round off it.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function standingsTestStage(string $format, int $entrants, array $settings = []): Stage
{
    $competition = Competition::factory()->create();

    for ($i = 1; $i <= $entrants; $i++) {
        $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

        Entrant::factory()->create([
            'competition_id' => $competition->id,
            'competition_team_id' => $team->id,
            'seed' => $i,
        ]);
    }

    return Stage::factory()->format($format, $settings)->create(['competition_id' => $competition->id]);
}

/**
 * Plays a match, winning it for whoever holds the given slot.
 */
function standingsTestPlay(CompetitionMatch $match, int $winningSlot = 1): string
{
    $slots = MatchSlot::query()
        ->where('competition_match_id', $match->id)
        ->orderBy('position')
        ->get();

    foreach ($slots as $slot) {
        $slot->forceFill(['score' => $slot->position === $winningSlot ? 1 : 0])->save();
    }

    $winnerId = (string) $slots->firstWhere('position', $winningSlot)?->entrant_id;

    app(RecordMatchResult::class)->handle($match, [], $winnerId);
    app(AdvanceBracket::class)->handle($match->fresh());

    return $winnerId;
}

/**
 * Plays every unfinished match of a round.
 */
function standingsTestPlayRound(Stage $stage, int $round): void
{
    $matches = CompetitionMatch::query()
        ->where('stage_id', $stage->id)
        ->where('round', $round)
        ->orderBy('position')
        ->get();

    foreach ($matches as $match) {
        if ($match->winner_entrant_id !== null) {
            continue;
        }

        standingsTestPlay($match);
    }
}

it('registers a calculator for every format', function (): void {
    expect(app(StandingsCalculatorRegistry::class)->keys())->toEqualCanonicalizing([
        'single_elimination', 'double_elimination', 'round_robin', 'swiss', 'group_stage',
    ]);
});

it('refuses a format it cannot rank rather than guessing', function (): void {
    expect(fn () => app(StandingsCalculatorRegistry::class)->get('race_heat'))
        ->toThrow(RuntimeException::class);
});

it('persists a row per entrant with the placement it calculated', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('round_robin', 4);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);
    app(CalculateStandings::class)->handle($stage);

    $standings = Standing::query()->where('stage_id', $stage->id)->orderBy('placement')->get();

    expect($standings)->toHaveCount(4)
        ->and($standings->first()->placement)->toBe(1)
        ->and($standings->first()->computed_at)->not->toBeNull();
});

it('replaces the table on recompute rather than merging into it', function (): void {
    // A second run must leave the table as the calculator returned it, not as
    // the union of two runs.
    $this->withBrand();

    $stage = standingsTestStage('round_robin', 4);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);

    app(CalculateStandings::class)->handle($stage);
    app(CalculateStandings::class)->handle($stage);

    expect(Standing::query()->where('stage_id', $stage->id)->count())->toBe(4);
});

it('drops an entrant who left the field', function (): void {
    // A withdrawal must remove the row, not strand it at its last placement.
    $this->withBrand();

    $stage = standingsTestStage('round_robin', 4);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);
    app(CalculateStandings::class)->handle($stage);

    $entrant = Entrant::query()->where('competition_id', $stage->competition_id)->firstOrFail();
    $entrant->forceFill(['state' => EntrantState::Withdrawn])->save();

    app(CalculateStandings::class)->handle($stage);

    expect(Standing::query()->where('stage_id', $stage->id)->count())->toBe(3)
        ->and(Standing::query()->where('entrant_id', $entrant->id)->exists())->toBeFalse();
});

it('ranks a finished single-elimination bracket by round eliminated', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('single_elimination', 4);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);
    standingsTestPlayRound($stage, 2);

    app(CalculateStandings::class)->handle($stage);

    $placements = Standing::query()->where('stage_id', $stage->id)
        ->orderBy('placement')->pluck('placement')->all();

    // 1st, 2nd, and a shared 3rd — no 4th, because no match decided one.
    expect($placements)->toBe([1, 2, 3, 3]);
});

it('reports nothing for a bracket whose final has not been played', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('single_elimination', 4);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);
    app(CalculateStandings::class)->handle($stage);

    expect(Standing::query()->where('stage_id', $stage->id)->count())->toBe(0);
});

it('tags each group-stage row with its group', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('group_stage', 8, ['group_count' => 2]);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);
    app(CalculateStandings::class)->handle($stage);

    $groups = Standing::query()->where('stage_id', $stage->id)
        ->pluck('group')->unique()->sort()->values()->all();

    expect($groups)->toBe(['group-A', 'group-B']);
});

it('pairs a second Swiss round off the standings it just recomputed', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('swiss', 8);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);

    $plan = app(PairNextSwissRound::class)->handle($stage->fresh());

    expect($plan->matches)->toHaveCount(4)
        ->and(CompetitionMatch::query()->where('stage_id', $stage->id)->where('round', 2)->count())->toBe(4);
});

it('never repeats a pairing the second Swiss round could avoid', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('swiss', 8);
    app(GenerateBracket::class)->handle($stage);

    $roundOne = CompetitionMatch::query()->where('stage_id', $stage->id)->where('round', 1)->get();

    $played = [];

    foreach ($roundOne as $match) {
        $ids = MatchSlot::query()->where('competition_match_id', $match->id)
            ->orderBy('position')->pluck('entrant_id')->all();
        sort($ids);
        $played[] = implode('|', $ids);
    }

    standingsTestPlayRound($stage, 1);
    app(PairNextSwissRound::class)->handle($stage->fresh());

    $roundTwo = CompetitionMatch::query()->where('stage_id', $stage->id)->where('round', 2)->get();

    foreach ($roundTwo as $match) {
        $ids = MatchSlot::query()->where('competition_match_id', $match->id)
            ->orderBy('position')->pluck('entrant_id')->all();
        sort($ids);

        expect($played)->not->toContain(implode('|', $ids));
    }
});

it('refuses to pair a Swiss round while the previous one is unfinished', function (): void {
    // Pairing on partial standings draws a round the remaining results
    // immediately invalidate.
    $this->withBrand();

    $stage = standingsTestStage('swiss', 8);
    app(GenerateBracket::class)->handle($stage);

    $first = CompetitionMatch::query()->where('stage_id', $stage->id)
        ->where('round', 1)->where('position', 1)->firstOrFail();

    standingsTestPlay($first);

    expect(fn () => app(PairNextSwissRound::class)->handle($stage->fresh()))
        ->toThrow(RuntimeException::class);
});

it('refuses to pair rounds onto a format that holds a graph', function (): void {
    $this->withBrand();

    $stage = standingsTestStage('single_elimination', 4);
    app(GenerateBracket::class)->handle($stage);

    expect(fn () => app(PairNextSwissRound::class)->handle($stage->fresh()))
        ->toThrow(RuntimeException::class);
});

it('refuses to pair past the stage round count', function (): void {
    // Eight entrants is a three-round Swiss.
    $this->withBrand();

    $stage = standingsTestStage('swiss', 8, ['total_rounds' => 1]);
    app(GenerateBracket::class)->handle($stage);

    standingsTestPlayRound($stage, 1);

    expect(fn () => app(PairNextSwissRound::class)->handle($stage->fresh()))
        ->toThrow(RuntimeException::class);
});
