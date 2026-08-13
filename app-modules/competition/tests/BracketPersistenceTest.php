<?php

declare(strict_types=1);

use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * The generators are proved in `BracketMathematicsTest` without a database.
 * This covers the other half: that a plan is persisted faithfully and that
 * winners actually move along the graph.
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function persistTestStage(string $format, int $entrants, array $settings = []): Stage
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

it('registers every absorbed format', function (): void {
    expect(app(FormatRegistry::class)->keys())->toEqualCanonicalizing([
        'single_elimination', 'double_elimination', 'round_robin', 'swiss', 'group_stage',
    ]);
});

it('refuses a format it does not know rather than guessing', function (): void {
    expect(fn () => app(FormatRegistry::class)->get('race_heat'))->toThrow(RuntimeException::class);
});

it('persists a single-elimination bracket with its slots and edges', function (): void {
    $this->withBrand();

    $stage = persistTestStage('single_elimination', 8);

    app(GenerateBracket::class)->handle($stage);

    expect(CompetitionMatch::query()->where('stage_id', $stage->id)->count())->toBe(7)
        ->and(MatchConnection::query()->count())->toBe(6)
        ->and($stage->fresh()->isGenerated())->toBeTrue();

    // Every match has both slots, filled or waiting.
    foreach (CompetitionMatch::query()->where('stage_id', $stage->id)->get() as $match) {
        expect(MatchSlot::query()->where('competition_match_id', $match->id)->count())->toBe(2);
    }
});

it('seeds round one in seed order, not creation order', function (): void {
    // A bracket must be reproducible from the same field.
    $this->withBrand();

    $stage = persistTestStage('single_elimination', 8);
    app(GenerateBracket::class)->handle($stage);

    $first = CompetitionMatch::query()
        ->where('stage_id', $stage->id)
        ->where('round', 1)->where('position', 1)
        ->firstOrFail();

    $seeds = MatchSlot::query()
        ->where('competition_match_id', $first->id)
        ->orderBy('position')
        ->get()
        ->map(fn (MatchSlot $slot): ?int => Entrant::query()->find($slot->entrant_id)?->seed)
        ->all();

    // Standard seeding: 1 versus 8 in an eight-bracket.
    expect($seeds)->toBe([1, 8]);
});

it('refuses to regenerate a stage rather than orphaning results', function (): void {
    $this->withBrand();

    $stage = persistTestStage('single_elimination', 4);
    app(GenerateBracket::class)->handle($stage);

    expect(fn () => app(GenerateBracket::class)->handle($stage->fresh()))
        ->toThrow(RuntimeException::class);
});

it('advances a winner into the slot the graph says', function (): void {
    $this->withBrand();

    $stage = persistTestStage('single_elimination', 4);
    app(GenerateBracket::class)->handle($stage);

    $match = CompetitionMatch::query()
        ->where('stage_id', $stage->id)->where('round', 1)->where('position', 1)
        ->firstOrFail();

    $winnerId = MatchSlot::query()->where('competition_match_id', $match->id)
        ->where('position', 1)->firstOrFail()->entrant_id;

    app(RecordMatchResult::class)->handle($match, [], $winnerId);
    app(AdvanceBracket::class)->handle($match->fresh());

    $final = CompetitionMatch::query()
        ->where('stage_id', $stage->id)->where('round', 2)
        ->firstOrFail();

    expect(MatchSlot::query()->where('competition_match_id', $final->id)->where('position', 1)
        ->firstOrFail()->entrant_id)->toBe($winnerId);
});

it('is idempotent when advancing twice', function (): void {
    // Re-advancing after a correction must not duplicate anyone.
    $this->withBrand();

    $stage = persistTestStage('single_elimination', 4);
    app(GenerateBracket::class)->handle($stage);

    $match = CompetitionMatch::query()
        ->where('stage_id', $stage->id)->where('round', 1)->where('position', 1)
        ->firstOrFail();

    $winnerId = MatchSlot::query()->where('competition_match_id', $match->id)
        ->where('position', 1)->firstOrFail()->entrant_id;

    app(RecordMatchResult::class)->handle($match, [], $winnerId);

    expect(app(AdvanceBracket::class)->handle($match->fresh()))->toHaveCount(1)
        ->and(app(AdvanceBracket::class)->handle($match->fresh()))->toBe([]);
});

it('sends a double-elimination loser down as well as the winner up', function (): void {
    $this->withBrand();

    $stage = persistTestStage('double_elimination', 8);
    app(GenerateBracket::class)->handle($stage);

    $match = CompetitionMatch::query()
        ->where('stage_id', $stage->id)->where('bracket', 'winners')
        ->where('round', 1)->where('position', 1)
        ->firstOrFail();

    $slots = MatchSlot::query()->where('competition_match_id', $match->id)->orderBy('position')->get();
    $winnerId = $slots[0]->entrant_id;
    $loserId = $slots[1]->entrant_id;

    app(RecordMatchResult::class)->handle($match, [], $winnerId);
    $touched = app(AdvanceBracket::class)->handle($match->fresh());

    // Two destinations: up the winners bracket, down into the losers bracket.
    expect($touched)->toHaveCount(2);

    $losersMatch = CompetitionMatch::query()->whereIn('id', $touched)
        ->where('bracket', 'losers')->firstOrFail();

    expect(MatchSlot::query()->where('competition_match_id', $losersMatch->id)
        ->where('entrant_id', $loserId)->exists())->toBeTrue();
});

it('persists a round robin with no edges at all', function (): void {
    $this->withBrand();

    $stage = persistTestStage('round_robin', 5);
    app(GenerateBracket::class)->handle($stage);

    // C(5,2) = 10 fixtures, and standings rather than a graph decide it.
    expect(CompetitionMatch::query()->where('stage_id', $stage->id)->count())->toBe(10)
        ->and(MatchConnection::query()->count())->toBe(0);
});

it('persists a group stage tagged by group', function (): void {
    $this->withBrand();

    $stage = persistTestStage('group_stage', 8, ['group_count' => 2]);
    app(GenerateBracket::class)->handle($stage);

    $brackets = CompetitionMatch::query()->where('stage_id', $stage->id)
        ->pluck('bracket')->unique()->sort()->values()->all();

    expect($brackets)->toBe(['group-A', 'group-B']);
});
