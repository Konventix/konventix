<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Actions\RegenerateBracket;
use Konventix\Competition\Actions\SeedStage;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Brackets\Plan\PlannedMatch;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\StageEntrySource;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Seeding\ManualSeedSource;
use Konventix\Competition\Seeding\RandomSeedSource;
use Konventix\Competition\Seeding\SeedSourceRegistry;
use Konventix\Competition\Standings\MatchOutcome;
use Konventix\Competition\Standings\MatchSide;

/**
 * Seeding, regeneration and the multi-stage seam (design §7.3, §7.4, §7.5).
 *
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function seedingTestStage(int $entrants, CheckInMode $checkInMode = CheckInMode::None, string $format = 'single_elimination'): Stage
{
    $competition = Competition::factory()->create(['check_in_mode' => $checkInMode]);

    for ($i = 1; $i <= $entrants; $i++) {
        $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

        Entrant::factory()->create([
            'competition_id' => $competition->id,
            'competition_team_id' => $team->id,
            'state' => $checkInMode->requiresCheckIn() ? EntrantState::CheckedIn : EntrantState::Registered,
            'checked_in_at' => $checkInMode->requiresCheckIn() ? now() : null,
            'seed' => null,
        ]);
    }

    return Stage::factory()->format($format)->create(['competition_id' => $competition->id]);
}

/**
 * The competition's entrants in creation order — the order a seed source is
 * meant to *replace*, so every assertion below has something to differ from.
 *
 * @return list<string>
 */
function seedingTestEntrantIds(Stage $stage): array
{
    return array_values(array_map(strval(...), Entrant::query()
        ->where('competition_id', $stage->competition_id)
        ->orderBy('created_at')
        ->pluck('id')
        ->all()));
}

/**
 * @return array<string, int|null> entrant id => seed
 */
function seedingTestSeeds(Stage $stage): array
{
    return Entrant::query()
        ->where('competition_id', $stage->competition_id)
        ->pluck('seed', 'id')
        ->all();
}

/**
 * Every round-one pairing as entrant ids, keyed by match position.
 *
 * @return array<int, list<string|null>>
 */
function seedingTestFirstRound(Stage $stage): array
{
    $pairings = [];

    $matches = CompetitionMatch::query()
        ->where('stage_id', $stage->id)
        ->where('round', 1)
        ->orderBy('position')
        ->get();

    foreach ($matches as $match) {
        $pairings[$match->position] = MatchSlot::query()
            ->where('competition_match_id', $match->id)
            ->orderBy('position')
            ->pluck('entrant_id')
            ->all();
    }

    return $pairings;
}

describe('seed sources (§7.5)', function (): void {
    it('numbers the whole ready field from one', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(6);

        $order = app(SeedStage::class)->handle($stage);

        expect($order)->toHaveCount(6)
            ->and(array_values(seedingTestSeeds($stage)))->toEqualCanonicalizing([1, 2, 3, 4, 5, 6])
            ->and(seedingTestSeeds($stage)[$order[0]])->toBe(1)
            ->and(seedingTestSeeds($stage)[$order[5]])->toBe(6);
    });

    it('seeds only the entrants the check-in mode counts as ready', function (): void {
        // Design §6, §7.5: a competition that checks its teams in seeds the
        // checked-in ones only, and a no-show is simply not drawn.
        $this->withBrand();

        $stage = seedingTestStage(3, CheckInMode::Desk);

        $absentee = Entrant::query()->where('competition_id', $stage->competition_id)->firstOrFail();
        $absentee->forceFill(['state' => EntrantState::Registered, 'checked_in_at' => null])->save();

        $order = app(SeedStage::class)->handle($stage);

        expect($order)->toHaveCount(2)
            ->and($order)->not->toContain($absentee->id)
            ->and(seedingTestSeeds($stage)[$absentee->id])->toBeNull();
    });

    it('writes the desk\'s drag order exactly and puts the rest behind it', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(4);
        [$first, $second, $third, $fourth] = seedingTestEntrantIds($stage);

        $order = app(SeedStage::class)->handle($stage, new ManualSeedSource([$third, $first]));

        // The two dragged entrants take seeds 1 and 2; the two that were not
        // dragged fall in behind in the field's own order.
        expect($order)->toBe([$third, $first, $second, $fourth])
            ->and(seedingTestSeeds($stage))->toEqual([
                $third => 1,
                $first => 2,
                $second => 3,
                $fourth => 4,
            ]);
    });

    it('refuses a seed order naming somebody who is not in the field', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(3);
        $stranger = (string) Str::ulid();

        expect(fn () => app(SeedStage::class)->handle($stage, new ManualSeedSource([$stranger])))
            ->toThrow(RuntimeException::class);
    });

    it('refuses a seed order that names the same entrant twice', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(3);
        [$first] = seedingTestEntrantIds($stage);

        expect(fn () => app(SeedStage::class)->handle($stage, new ManualSeedSource([$first, $first])))
            ->toThrow(RuntimeException::class);
    });

    it('re-shuffles a field that was already seeded', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(4);
        [$first, $second, $third, $fourth] = seedingTestEntrantIds($stage);

        app(SeedStage::class)->handle($stage, new ManualSeedSource([$fourth, $third, $second, $first]));
        app(SeedStage::class)->handle($stage, new ManualSeedSource([$second, $first]));

        // Derived by hand from the code, step by step:
        //
        // 1. The first call writes the full drag order: fourth=1, third=2,
        //    second=3, first=4.
        // 2. The second call asks `ManualSeedSource::seedOrder()` for the
        //    order BEFORE anything is cleared — `SeedStage::handle()` computes
        //    it first and nulls the old seeds only afterwards. So
        //    `SignupField::entrantIds()` (`seed IS NULL`, then `seed`, then
        //    `created_at`) reads the field in the previous seed order:
        //    [fourth, third, second, first].
        // 3. `seedOrder()` takes the dragged pair [second, first] and appends
        //    the undragged rest in that field order, skipping the two already
        //    placed: [second, first] + [fourth, third].
        // 4. `SeedStage` clears every seed and numbers that list from one:
        //    second=1, first=2, fourth=3, third=4.
        //
        // So the undragged pair keeps its relative order from the previous
        // shuffle (fourth ahead of third), not creation order — and no
        // entrant keeps a stale number from the previous order.
        expect(seedingTestSeeds($stage))->toEqual([
            $second => 1,
            $first => 2,
            $fourth => 3,
            $third => 4,
        ]);
    });
});

describe('regeneration (§7.5)', function (): void {
    it('redraws a stage that has not been played yet', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(4);
        app(SeedStage::class)->handle($stage);
        app(GenerateBracket::class)->handle($stage);

        $before = CompetitionMatch::query()->where('stage_id', $stage->id)->pluck('id')->all();

        app(RegenerateBracket::class)->handle($stage->fresh());

        $after = CompetitionMatch::query()->where('stage_id', $stage->id)->pluck('id')->all();

        expect($before)->toHaveCount(3)
            ->and($after)->toHaveCount(3)
            // The old matches are gone rather than joined by three more.
            ->and(array_intersect($before, $after))->toBe([])
            ->and($stage->fresh()->isGenerated())->toBeTrue();
    });

    it('refuses to redraw once a result has been recorded', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(4);
        app(SeedStage::class)->handle($stage);
        app(GenerateBracket::class)->handle($stage);

        $match = CompetitionMatch::query()
            ->where('stage_id', $stage->id)->where('round', 1)->where('position', 1)
            ->firstOrFail();

        $winnerId = (string) MatchSlot::query()
            ->where('competition_match_id', $match->id)->where('position', 1)
            ->firstOrFail()->entrant_id;

        app(RecordMatchResult::class)->handle($match, [], $winnerId);

        expect(fn () => app(RegenerateBracket::class)->handle($stage->fresh()))
            ->toThrow(RuntimeException::class);
    });

    it('refuses to re-seed once a result has been recorded', function (): void {
        // Seeds break ties in every standings calculator, so renumbering the
        // field after a result rewrites the table the results produced.
        $this->withBrand();

        $stage = seedingTestStage(4);
        app(SeedStage::class)->handle($stage);
        app(GenerateBracket::class)->handle($stage);

        $match = CompetitionMatch::query()
            ->where('stage_id', $stage->id)->where('round', 1)->where('position', 1)
            ->firstOrFail();

        MatchSlot::query()
            ->where('competition_match_id', $match->id)
            ->where('position', 1)
            ->update(['score' => 13]);

        expect(fn () => app(SeedStage::class)->handle($stage->fresh()))
            ->toThrow(RuntimeException::class);
    });

    it('keeps the manual seed order across a regeneration', function (): void {
        $this->withBrand();

        $stage = seedingTestStage(4);
        [$first, $second, $third, $fourth] = seedingTestEntrantIds($stage);

        // A deliberate order that is not creation order, so a regeneration
        // that lost the seeds would draw a visibly different bracket.
        app(SeedStage::class)->handle($stage, new ManualSeedSource([$third, $first, $fourth, $second]));
        app(GenerateBracket::class)->handle($stage);

        // Standard seeding in a four-bracket: 1 v 4 and 2 v 3.
        $drawn = [1 => [$third, $second], 2 => [$first, $fourth]];

        expect(seedingTestFirstRound($stage))->toBe($drawn);

        app(RegenerateBracket::class)->handle($stage->fresh());

        expect(seedingTestSeeds($stage))->toEqual([
            $third => 1,
            $first => 2,
            $fourth => 3,
            $second => 4,
        ])->and(seedingTestFirstRound($stage))->toBe($drawn);
    });
});

describe('the multi-stage seam (§7.3)', function (): void {
    it('starts every stage from signup and carries an advance count', function (): void {
        $this->withBrand();

        $signup = Stage::factory()->create();

        expect($signup->entry_source)->toBe(StageEntrySource::Signup)
            ->and($signup->advance_count)->toBeNull();

        $group = Stage::factory()
            ->entrySource(StageEntrySource::PreviousStage, 4)
            ->create(['sequence' => 2, 'competition_id' => $signup->competition_id]);

        expect($group->fresh()->entry_source)->toBe(StageEntrySource::PreviousStage)
            ->and($group->fresh()->advance_count)->toBe(4);
    });

    it('resolves a signup stage to the random source and refuses the unbuilt one', function (): void {
        $registry = app(SeedSourceRegistry::class);

        expect($registry->keys())->toBe([StageEntrySource::Signup->value])
            ->and($registry->get(StageEntrySource::Signup))->toBeInstanceOf(RandomSeedSource::class)
            ->and($registry->has(StageEntrySource::PreviousStage))->toBeFalse()
            ->and(fn () => $registry->get(StageEntrySource::PreviousStage))->toThrow(RuntimeException::class);
    });

    it('refuses to seed a stage whose entrants would come from a stage it cannot read', function (): void {
        // The alternative — falling back to signup — would seed the final of
        // a qualifier with everybody who ever entered, and the bracket would
        // look perfectly plausible.
        $this->withBrand();

        $stage = seedingTestStage(4);
        $stage->forceFill(['entry_source' => StageEntrySource::PreviousStage, 'advance_count' => 2])->save();

        expect(fn () => app(SeedStage::class)->handle($stage->fresh()))
            ->toThrow(RuntimeException::class);
    });
});

describe('match sides (§7.4)', function (): void {
    it('has every generator produce exactly two sides per match', function (): void {
        // §7.4's claim, and what every head-to-head path in §8 is written
        // against. It is asserted rather than assumed because the slot table
        // does not enforce it.
        $registry = app(FormatRegistry::class);

        foreach ($registry->keys() as $format) {
            foreach ([3, 4, 5, 8] as $size) {
                $entrantIds = array_map(static fn (int $i): string => sprintf('S%03d', $i), range(1, $size));

                $plan = $registry->get($format)->generate($entrantIds, $registry->get($format)->defaults());

                foreach ($plan->matches as $match) {
                    expect($match)->toBeInstanceOf(PlannedMatch::class)
                        ->and(array_keys($match->entrantIds))->toBe([1, 2]);
                }
            }
        }
    });

    it('lets a match carry a third side, which is not a head-to-head', function (): void {
        // `match_slots.position` is a column rather than two match columns so
        // a free-for-all heat needs no schema change (§7.4). Nothing
        // generates one yet; the standings path recognises it and steps over
        // it rather than treating the third entrant as a bye.
        $this->withBrand();

        $stage = seedingTestStage(3);
        $entrantIds = seedingTestEntrantIds($stage);

        $match = new CompetitionMatch([
            'competition_id' => $stage->competition_id,
            'stage_id' => $stage->id,
            'round' => 1,
            'position' => 1,
        ]);
        $match->forceFill(['brand_id' => $stage->brand_id])->save();

        foreach ($entrantIds as $index => $entrantId) {
            $slot = new MatchSlot([
                'competition_match_id' => $match->id,
                'entrant_id' => $entrantId,
                'position' => $index + 1,
            ]);
            $slot->forceFill(['brand_id' => $stage->brand_id])->save();
        }

        $outcome = new MatchOutcome(
            round: 1,
            sides: array_map(static fn (string $id): MatchSide => new MatchSide($id, 1), $entrantIds),
        );

        expect(MatchSlot::query()->where('competition_match_id', $match->id)->count())->toBe(3)
            ->and($outcome->isHeadToHead())->toBeFalse()
            ->and($outcome->loserEntrantId())->toBeNull();
    });
});
