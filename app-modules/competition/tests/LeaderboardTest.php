<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\CalculateStandings;
use Konventix\Competition\Actions\RejectScoreEntry;
use Konventix\Competition\Actions\SubmitScoreEntry;
use Konventix\Competition\Actions\VerifyScoreEntry;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Data\ScoreEntryData;
use Konventix\Competition\Enums\LeaderboardAggregate;
use Konventix\Competition\Enums\LeaderboardDirection;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Models\Standing;
use Konventix\Competition\Standings\LeaderboardStandingsCalculator;
use Konventix\Competition\Standings\ScoredEntry;
use Konventix\Competition\Standings\StandingEntry;
use Konventix\Competition\Standings\StandingsEntrant;

/**
 * The leaderboard format (design §7.2, §8.3).
 *
 * Two halves, the same split `BracketMathematicsTest` and
 * `BracketPersistenceTest` use: the ranking is proved in memory against
 * totals worked out by hand, and the database half proves only that the
 * submit-and-verify rules hold and that a verified score reaches the
 * standings table.
 *
 * The four archetypes below are SXLAN's real non-paired tournaments, which
 * were all forced into a fake single-elimination bracket before this format
 * existed: Fall Guys and PUBG (points summed over heats), Trackmania (best
 * time, lower wins) and Casemod (a jury average).
 *
 * Helper names prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function leaderboardTestId(int $seed): string
{
    return sprintf('L%03d', $seed);
}

/**
 * @return list<StandingsEntrant>
 */
function leaderboardTestField(int $count): array
{
    return array_map(
        fn (int $seed): StandingsEntrant => new StandingsEntrant(leaderboardTestId($seed), $seed),
        range(1, $count),
    );
}

function leaderboardTestEntry(int $seed, float $value, ?int $heat = null, bool $verified = true): ScoredEntry
{
    return new ScoredEntry(
        entrantId: leaderboardTestId($seed),
        value: $value,
        heat: $heat,
        verified: $verified,
    );
}

/**
 * Every heat of one entrant at once: `[heat => value]`.
 *
 * @param  array<int, float>  $byHeat
 * @return list<ScoredEntry>
 */
function leaderboardTestHeats(int $seed, array $byHeat, bool $verified = true): array
{
    $entries = [];

    foreach ($byHeat as $heat => $value) {
        $entries[] = leaderboardTestEntry($seed, $value, $heat, $verified);
    }

    return $entries;
}

/**
 * @param  list<StandingEntry>  $entries
 * @return array<string, int> entrant id => placement
 */
function leaderboardTestPlacements(array $entries): array
{
    $placements = [];

    foreach ($entries as $entry) {
        $placements[$entry->entrantId] = $entry->placement;
    }

    return $placements;
}

/**
 * @param  list<StandingEntry>  $entries
 * @return list<string>
 */
function leaderboardTestOrder(array $entries): array
{
    return array_map(static fn (StandingEntry $entry): string => $entry->entrantId, $entries);
}

/**
 * A persisted leaderboard stage with `$entrants` entrants, seeded 1..n.
 *
 * @return array{0: Stage, 1: list<Entrant>}
 */
function leaderboardTestStage(
    LeaderboardSettings $settings,
    int $entrants = 3,
    ProofPolicy $proofPolicy = ProofPolicy::None,
    ResultSubmissionMode $submissionMode = ResultSubmissionMode::Participants,
): array {
    // `participants` explicitly, because the column defaults to `desk_only`
    // and §8.1's knob is enforced in `SubmitScoreEntry`: a score entry is a
    // participant reporting a result, so a `desk_only` competition refuses
    // one. The tests below that submit as a participant need a competition
    // that takes participant submissions.
    $competition = Competition::factory()->create([
        'proof_policy' => $proofPolicy,
        'result_submission_mode' => $submissionMode,
    ]);

    $field = [];

    for ($i = 1; $i <= $entrants; $i++) {
        $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

        $field[] = Entrant::factory()->create([
            'competition_id' => $competition->id,
            'competition_team_id' => $team->id,
            'seed' => $i,
        ]);
    }

    $stage = Stage::factory()->leaderboard($settings)->create(['competition_id' => $competition->id]);

    return [$stage, $field];
}

describe('the ranking (§7.2)', function (): void {
    it('sums the heats of a points leaderboard and ranks the totals', function (): void {
        // Fall Guys: five heats, points per heat, higher is better.
        // Hand totals: 10+5+0+20+5 = 40, 20+20+10+5+5 = 60,
        //              5+10+20+10+10 = 55, 0+0+5+0+20 = 25.
        $entries = [
            ...leaderboardTestHeats(1, [1 => 10.0, 2 => 5.0, 3 => 0.0, 4 => 20.0, 5 => 5.0]),
            ...leaderboardTestHeats(2, [1 => 20.0, 2 => 20.0, 3 => 10.0, 4 => 5.0, 5 => 5.0]),
            ...leaderboardTestHeats(3, [1 => 5.0, 2 => 10.0, 3 => 20.0, 4 => 10.0, 5 => 10.0]),
            ...leaderboardTestHeats(4, [1 => 0.0, 2 => 0.0, 3 => 5.0, 4 => 0.0, 5 => 20.0]),
        ];

        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            leaderboardTestField(4),
            $entries,
            new LeaderboardSettings(
                direction: LeaderboardDirection::Higher,
                aggregate: LeaderboardAggregate::Sum,
                heats: 5,
                unit: 'pts',
            ),
        );

        // Seed order is 1, 2, 3, 4 — so a calculator that ignored the
        // totals and fell back to seed would produce exactly the wrong
        // answer here.
        expect(leaderboardTestOrder($ranked))->toBe(['L002', 'L003', 'L001', 'L004'])
            ->and(leaderboardTestPlacements($ranked))->toBe(['L002' => 1, 'L003' => 2, 'L001' => 3, 'L004' => 4])
            ->and(array_map(static fn (StandingEntry $e): float => $e->tiebreaker, $ranked))
            ->toBe([60.0, 55.0, 40.0, 25.0]);
    });

    it('takes the best of several attempts when lower wins', function (): void {
        // Three timed runs each; the best lap is the smallest number.
        // Hand bests: 41.900, 41.950, 43.000.
        $entries = [
            ...leaderboardTestHeats(1, [1 => 42.117, 2 => 41.900, 3 => 42.400]),
            ...leaderboardTestHeats(2, [1 => 41.998, 2 => 42.500, 3 => 41.950]),
            ...leaderboardTestHeats(3, [1 => 43.500, 2 => 43.100, 3 => 43.000]),
        ];

        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            leaderboardTestField(3),
            $entries,
            new LeaderboardSettings(
                direction: LeaderboardDirection::Lower,
                aggregate: LeaderboardAggregate::Best,
                heats: 3,
                unit: 's',
                decimals: 3,
            ),
        );

        expect(leaderboardTestOrder($ranked))->toBe(['L001', 'L002', 'L003'])
            ->and(array_map(static fn (StandingEntry $e): float => $e->tiebreaker, $ranked))
            ->toBe([41.900, 41.950, 43.000]);
    });

    it('ranks the single resubmittable entry of a heatless leaderboard', function (): void {
        // Trackmania as §7.2 describes it: heats null, one entry per
        // entrant, lower wins.
        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            leaderboardTestField(3),
            [
                leaderboardTestEntry(1, 42.117),
                leaderboardTestEntry(2, 41.998),
                leaderboardTestEntry(3, 43.500),
            ],
            new LeaderboardSettings(
                direction: LeaderboardDirection::Lower,
                aggregate: LeaderboardAggregate::Best,
                unit: 's',
                decimals: 3,
            ),
        );

        expect(leaderboardTestOrder($ranked))->toBe(['L002', 'L001', 'L003']);
    });

    it('averages the jurors and lets an inseparable pair share the placement', function (): void {
        // Casemod: one entry per juror, higher wins.
        // Hand averages: (8+9+7)/3 = 8.0, (9+9+9)/3 = 9.0, (6+10+8)/3 = 8.0.
        $entries = [
            ...leaderboardTestHeats(1, [1 => 8.0, 2 => 9.0, 3 => 7.0]),
            ...leaderboardTestHeats(2, [1 => 9.0, 2 => 9.0, 3 => 9.0]),
            ...leaderboardTestHeats(3, [1 => 6.0, 2 => 10.0, 3 => 8.0]),
        ];

        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            leaderboardTestField(3),
            $entries,
            new LeaderboardSettings(
                direction: LeaderboardDirection::Higher,
                aggregate: LeaderboardAggregate::Average,
                heats: 3,
                unit: 'pts',
                decimals: 1,
            ),
        );

        // Standard competition ranking: two seconds, and nobody is third.
        expect(leaderboardTestPlacements($ranked))->toBe(['L002' => 1, 'L001' => 2, 'L003' => 2])
            ->and(leaderboardTestOrder($ranked))->toBe(['L002', 'L001', 'L003']);
    });

    it('counts an entry only once an organizer has verified it', function (): void {
        $field = leaderboardTestField(2);
        $settings = new LeaderboardSettings(
            direction: LeaderboardDirection::Higher,
            aggregate: LeaderboardAggregate::Best,
        );

        $claimed = [
            leaderboardTestEntry(1, 10.0),
            leaderboardTestEntry(2, 100.0, verified: false),
        ];

        // The unverified 100 does not win, and does not even rank: an
        // entrant with nothing that counts has no score at all.
        expect(leaderboardTestPlacements((new LeaderboardStandingsCalculator)->calculate($field, $claimed, $settings)))
            ->toBe(['L001' => 1, 'L002' => 2]);

        $verified = [
            leaderboardTestEntry(1, 10.0),
            leaderboardTestEntry(2, 100.0),
        ];

        expect(leaderboardTestPlacements((new LeaderboardStandingsCalculator)->calculate($field, $verified, $settings)))
            ->toBe(['L002' => 1, 'L001' => 2]);
    });

    it('never lets an entrant who submitted nothing outrank one who did', function (): void {
        // The rule the direction makes dangerous: on a `lower` leaderboard,
        // treating "no entry" as zero would put everybody who never drove a
        // lap at the top of the table.
        $field = leaderboardTestField(3);
        $entries = [
            leaderboardTestEntry(1, 42.0),
            leaderboardTestEntry(3, 44.0),
        ];

        $lower = (new LeaderboardStandingsCalculator)->calculate(
            $field,
            $entries,
            new LeaderboardSettings(direction: LeaderboardDirection::Lower, aggregate: LeaderboardAggregate::Best),
        );

        expect(leaderboardTestOrder($lower))->toBe(['L001', 'L003', 'L002'])
            ->and(leaderboardTestPlacements($lower))->toBe(['L001' => 1, 'L003' => 2, 'L002' => 3]);

        $higher = (new LeaderboardStandingsCalculator)->calculate(
            $field,
            $entries,
            new LeaderboardSettings(direction: LeaderboardDirection::Higher, aggregate: LeaderboardAggregate::Best),
        );

        expect(leaderboardTestOrder($higher))->toBe(['L003', 'L001', 'L002']);
    });

    it('shares the last placement between everyone who submitted nothing', function (): void {
        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            leaderboardTestField(4),
            [leaderboardTestEntry(1, 10.0), leaderboardTestEntry(3, 5.0)],
            new LeaderboardSettings(direction: LeaderboardDirection::Higher, aggregate: LeaderboardAggregate::Best),
        );

        expect(leaderboardTestPlacements($ranked))
            ->toBe(['L001' => 1, 'L003' => 2, 'L002' => 3, 'L004' => 3]);
    });

    it('ignores an entry that does not fit the stage\'s heat shape', function (): void {
        // A sixth heat on a two-heat stage, and a heatless entry on the same
        // stage, are rows that should never have been written. Counted, the
        // 1000s would decide the table.
        $entries = [
            ...leaderboardTestHeats(1, [1 => 5.0, 2 => 5.0, 3 => 1000.0]),
            leaderboardTestEntry(1, 1000.0),
            ...leaderboardTestHeats(2, [1 => 6.0, 2 => 6.0]),
        ];

        $ranked = (new LeaderboardStandingsCalculator)->calculate(
            leaderboardTestField(2),
            $entries,
            new LeaderboardSettings(
                direction: LeaderboardDirection::Higher,
                aggregate: LeaderboardAggregate::Sum,
                heats: 2,
            ),
        );

        expect(leaderboardTestOrder($ranked))->toBe(['L002', 'L001'])
            ->and(array_map(static fn (StandingEntry $e): float => $e->tiebreaker, $ranked))->toBe([12.0, 10.0]);
    });
});

describe('submitting and verifying (§8.3)', function (): void {
    it('replaces a participant\'s own unverified entry for the same heat', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings(
            direction: LeaderboardDirection::Lower,
            aggregate: LeaderboardAggregate::Best,
        ));

        $player = (string) Str::ulid();

        app(SubmitScoreEntry::class)->handle($stage, $field[0], new ScoreEntryData(value: 42.500), $player);
        app(SubmitScoreEntry::class)->handle($stage, $field[0], new ScoreEntryData(value: 41.900), $player);

        $entries = ScoreEntry::query()->where('stage_id', $stage->id)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->value)->toBe(41.900)
            ->and($entries->first()->isVerified())->toBeFalse();
    });

    it('refuses a participant who tries to overwrite a verified score', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings);

        $desk = (string) Str::ulid();
        $player = (string) Str::ulid();

        app(SubmitScoreEntry::class)->handle($stage, $field[0], new ScoreEntryData(value: 10.0), $desk, asOrganizer: true);

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 99.0), $player))
            ->toThrow(ValidationException::class);

        // The organizer may, and the row is verified again afterwards.
        app(SubmitScoreEntry::class)->handle($stage, $field[0], new ScoreEntryData(value: 99.0), $desk, asOrganizer: true);

        $entry = ScoreEntry::query()->where('stage_id', $stage->id)->sole();

        expect($entry->value)->toBe(99.0)->and($entry->isVerified())->toBeTrue();
    });

    it('verifies a desk-entered row on creation and leaves a participant\'s waiting', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings);

        $desk = app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 10.0), (string) Str::ulid(), asOrganizer: true);

        $claim = app(SubmitScoreEntry::class)
            ->handle($stage, $field[1], new ScoreEntryData(value: 20.0), (string) Str::ulid());

        expect($desk->isVerified())->toBeTrue()->and($claim->isVerified())->toBeFalse();
    });

    it('moves the standings only when the entry is verified', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings(
            direction: LeaderboardDirection::Higher,
            aggregate: LeaderboardAggregate::Best,
        ), entrants: 2);

        $claim = app(SubmitScoreEntry::class)
            ->handle($stage, $field[1], new ScoreEntryData(value: 100.0), (string) Str::ulid());

        app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 10.0), (string) Str::ulid(), asOrganizer: true);

        // Seed 1 scored ten and is verified; seed 2 claims a hundred and is
        // not. The table says seed 1 leads.
        $placements = Standing::query()
            ->where('stage_id', $stage->id)
            ->pluck('placement', 'entrant_id')
            ->all();

        expect($placements[$field[0]->id])->toBe(1)
            ->and($placements[$field[1]->id])->toBe(2);

        app(VerifyScoreEntry::class)->handle($claim, (string) Str::ulid());

        $after = Standing::query()
            ->where('stage_id', $stage->id)
            ->pluck('placement', 'entrant_id')
            ->all();

        expect($after[$field[1]->id])->toBe(1)
            ->and($after[$field[0]->id])->toBe(2);
    });

    it('recomputes a leaderboard stage through the standings Action itself', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings(
            direction: LeaderboardDirection::Higher,
            aggregate: LeaderboardAggregate::Sum,
            heats: 2,
        ), entrants: 2);

        $desk = (string) Str::ulid();

        foreach ([[$field[0], 1, 3.0], [$field[0], 2, 4.0], [$field[1], 1, 10.0]] as [$entrant, $heat, $value]) {
            app(SubmitScoreEntry::class)
                ->handle($stage, $entrant, new ScoreEntryData(value: $value, heat: $heat), $desk, asOrganizer: true);
        }

        // 3 + 4 = 7 against 10, both verified.
        $entries = app(CalculateStandings::class)->handle($stage->fresh());

        expect(array_map(static fn (StandingEntry $e): string => $e->entrantId, $entries))
            ->toBe([$field[1]->id, $field[0]->id])
            ->and(array_map(static fn (StandingEntry $e): float => $e->tiebreaker, $entries))
            ->toBe([10.0, 7.0]);
    });

    it('refuses a heat the stage does not have', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings(heats: 3));

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 1.0, heat: 4), (string) Str::ulid()))
            ->toThrow(ValidationException::class);

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 1.0), (string) Str::ulid()))
            ->toThrow(ValidationException::class);
    });

    it('refuses a heat number on a leaderboard that has no heats', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings);

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 1.0, heat: 1), (string) Str::ulid()))
            ->toThrow(ValidationException::class);
    });

    it('holds a score back when the competition requires a proof and none came', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings, proofPolicy: ProofPolicy::Required);

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 1.0), (string) Str::ulid()))
            ->toThrow(ValidationException::class);

        $entry = app(SubmitScoreEntry::class)->handle(
            $stage,
            $field[0],
            new ScoreEntryData(value: 1.0, proofMediaId: (string) Str::ulid()),
            (string) Str::ulid(),
        );

        expect($entry->proof_media_id)->not->toBeNull();
    });

    it('refuses a participant score entry when the competition is desk_only', function (): void {
        // §8.1's first knob, on the leaderboard side. "Whether participants
        // may report" covers §8.3's score entry, and the refusal is in the
        // Action rather than only on the form — otherwise a `desk_only`
        // organizer's verification queue fills with numbers typed by the
        // competitors.
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(
            new LeaderboardSettings,
            submissionMode: ResultSubmissionMode::DeskOnly,
        );

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 42.0), (string) Str::ulid()))
            ->toThrow(ResultSubmissionException::class);

        // "The desk can always record, whatever this says."
        $desk = app(SubmitScoreEntry::class)->handle(
            $stage,
            $field[0],
            new ScoreEntryData(value: 42.0),
            (string) Str::ulid(),
            asOrganizer: true,
        );

        expect($desk->isVerified())->toBeTrue()
            ->and(ScoreEntry::query()->where('stage_id', $stage->id)->count())->toBe(1);
    });

    it('rejects an entry with a note, keeps the row, and lets the participant resubmit', function (): void {
        // §8.3's other half: an organizer "rejects it with a note". The row
        // stays so the participant can see what was wrong with it.
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings(
            direction: LeaderboardDirection::Lower,
            aggregate: LeaderboardAggregate::Best,
        ));

        $player = (string) Str::ulid();
        $desk = (string) Str::ulid();

        $entry = app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 12.000), $player);

        $rejected = app(RejectScoreEntry::class)->handle($entry, $desk, 'Der Screenshot zeigt eine andere Strecke.');

        expect($rejected->isRejected())->toBeTrue()
            ->and($rejected->isVerified())->toBeFalse()
            ->and($rejected->rejection_note)->toBe('Der Screenshot zeigt eine andere Strecke.')
            ->and($rejected->rejected_by_user_id)->toBe($desk)
            // The value is still there to look at; rejecting is not deleting.
            ->and($rejected->value)->toBe(12.000);

        // Resubmitting replaces the value and clears the rejection with it.
        $resubmitted = app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 41.900), $player);

        expect($resubmitted->isRejected())->toBeFalse()
            ->and($resubmitted->rejection_note)->toBeNull()
            ->and($resubmitted->value)->toBe(41.900)
            ->and(ScoreEntry::query()->where('stage_id', $stage->id)->count())->toBe(1);
    });

    it('refuses a rejection with no note', function (): void {
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings);

        $entry = app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 1.0), (string) Str::ulid());

        expect(fn () => app(RejectScoreEntry::class)->handle($entry, (string) Str::ulid(), '  '))
            ->toThrow(ValidationException::class);
    });

    it('takes a rejected score back out of the standings', function (): void {
        // An organizer who verified a number and then found it wrong must not
        // leave the table standing on it.
        $this->withBrand();

        [$stage, $field] = leaderboardTestStage(new LeaderboardSettings(
            direction: LeaderboardDirection::Higher,
            aggregate: LeaderboardAggregate::Best,
        ), entrants: 2);

        $desk = (string) Str::ulid();

        app(SubmitScoreEntry::class)
            ->handle($stage, $field[0], new ScoreEntryData(value: 10.0), $desk, asOrganizer: true);

        $inflated = app(SubmitScoreEntry::class)
            ->handle($stage, $field[1], new ScoreEntryData(value: 100.0), $desk, asOrganizer: true);

        // 100 beats 10, so seed 2 leads while the number counts.
        expect(Standing::query()->where('stage_id', $stage->id)
            ->pluck('placement', 'entrant_id')->all()[$field[1]->id])->toBe(1);

        app(RejectScoreEntry::class)->handle($inflated, $desk, 'Nicht nachvollziehbar.');

        $after = Standing::query()->where('stage_id', $stage->id)->pluck('placement', 'entrant_id')->all();

        // Only seed 1's ten is left counting, so seed 1 is first — and seed 2
        // has no verified entry at all, so the leaderboard ranks it last.
        expect($after[$field[0]->id])->toBe(1)
            ->and($after[$field[1]->id])->toBe(2);
    });

    it('refuses a score entry on a stage that plays matches', function (): void {
        $this->withBrand();

        $competition = Competition::factory()->create();
        $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();
        $entrant = Entrant::factory()->create([
            'competition_id' => $competition->id,
            'competition_team_id' => $team->id,
        ]);
        $stage = Stage::factory()->format('single_elimination')->create(['competition_id' => $competition->id]);

        expect(fn () => app(SubmitScoreEntry::class)
            ->handle($stage, $entrant, new ScoreEntryData(value: 1.0), (string) Str::ulid()))
            ->toThrow(RuntimeException::class);
    });
});
