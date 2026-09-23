<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\EntrantCheckedIn;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Events\EntrantWithdrawn;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Observability\MetricDefinition;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * ADR-0059, design §13. The two counters are written by
 * `RecordCompetitionMetrics` from this module's own events; the two gauges
 * are answered by `CompetitionGauges` when `/metrics` is rendered.
 *
 * Every expected number below is counted by hand from the rows the test
 * makes, and the comment beside each says which rows count.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function competitionMetricsRendered(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

function competitionMetricsScrape(object $test): string
{
    config()->set('health.endpoint_secret', 'competition-metrics-secret');

    return $test->withHeader('Authorization', 'Bearer competition-metrics-secret')
        ->get('/metrics')
        ->assertOk()
        ->getContent();
}

it('counts entrants by the state they moved into, and finished matches once each', function (): void {
    $brand = $this->withBrand();
    $slug = BrandSlugs::of($brand->getKey());
    $id = fn (): string => (string) Str::ulid();

    // Two entries: one full roster, one short (§5.4).
    foreach (['registered', 'incomplete'] as $state) {
        event(new EntrantRegistered(
            entrantId: $id(),
            competitionId: $id(),
            competitionTeamId: $id(),
            eventId: $id(),
            brandId: (string) $brand->getKey(),
            registeredAt: now()->toIso8601String(),
            memberUserIds: [],
            state: $state,
        ));
    }

    event(new EntrantCheckedIn(
        entrantId: $id(),
        competitionId: $id(),
        competitionTeamId: $id(),
        eventId: $id(),
        brandId: (string) $brand->getKey(),
        memberUserIds: [],
        checkedInAt: now()->toIso8601String(),
        viaSelfService: true,
    ));

    // Labelled with the state the entrant ended in, not the reason.
    event(new EntrantWithdrawn(
        entrantId: $id(),
        competitionId: $id(),
        competitionTeamId: $id(),
        eventId: $id(),
        brandId: (string) $brand->getKey(),
        memberUserIds: [],
        reason: 'disqualified',
        finalState: 'disqualified',
        withdrawnAt: now()->toIso8601String(),
    ));

    event(new MatchFinished(
        matchId: $id(),
        competitionId: $id(),
        brandId: (string) $brand->getKey(),
        winnerEntrantId: null,
        scores: [],
        finishedAt: now()->toIso8601String(),
    ));

    expect(competitionMetricsRendered())
        ->toContain('konventix_competition_entrants_total{brand="'.$slug.'",state="registered"} 1')
        ->toContain('konventix_competition_entrants_total{brand="'.$slug.'",state="incomplete"} 1')
        ->toContain('konventix_competition_entrants_total{brand="'.$slug.'",state="checked_in"} 1')
        ->toContain('konventix_competition_entrants_total{brand="'.$slug.'",state="disqualified"} 1')
        ->toContain('konventix_competition_matches_total{brand="'.$slug.'",state="finished"} 1');
});

it('counts open disputes in running competitions only, with a zero row for every other brand', function (): void {
    $brand = $this->withBrand();
    $quiet = Brand::factory()->create(['slug' => 'no-tournaments']);

    $running = Competition::factory()->status(CompetitionStatus::Running)->create();
    $finished = Competition::factory()->status(CompetitionStatus::Finished)->create();

    // Counts: disputed, not resolved, in a running competition.
    CompetitionMatch::factory()->create(['competition_id' => $running->getKey(), 'disputed_at' => now()->subMinutes(40)]);
    CompetitionMatch::factory()->create(['competition_id' => $running->getKey(), 'disputed_at' => now()->subMinutes(5)]);
    // Does not count: the desk resolved it.
    CompetitionMatch::factory()->create([
        'competition_id' => $running->getKey(),
        'disputed_at' => now()->subHour(),
        'dispute_resolved_at' => now()->subMinutes(50),
    ]);
    // Does not count: never disputed.
    CompetitionMatch::factory()->create(['competition_id' => $running->getKey()]);
    // Does not count: the competition is over.
    CompetitionMatch::factory()->create(['competition_id' => $finished->getKey(), 'disputed_at' => now()->subHour()]);

    expect(competitionMetricsScrape($this))
        ->toContain('konventix_competition_disputes_open{brand="'.BrandSlugs::of($brand->getKey()).'"} 2')
        ->toContain('konventix_competition_disputes_open{brand="no-tournaments"} 0');

    expect($quiet->exists)->toBeTrue();
});

it('counts score entries waiting for the desk in competitions that are not over', function (): void {
    $brand = $this->withBrand();

    $running = Competition::factory()->status(CompetitionStatus::Running)->create();
    $cancelled = Competition::factory()->status(CompetitionStatus::Cancelled)->create();

    $stage = Stage::factory()->create(['competition_id' => $running->getKey()]);
    $oldStage = Stage::factory()->create(['competition_id' => $cancelled->getKey()]);

    $entry = function (Stage $stage, array $state = []): void {
        $entrant = Entrant::factory()->create(['competition_id' => $stage->competition_id]);

        (new ScoreEntry([
            'stage_id' => $stage->getKey(),
            'entrant_id' => $entrant->getKey(),
            'value' => 61.5,
            'submitted_by_user_id' => (string) Str::ulid(),
            ...$state,
        ]))->save();
    };

    // Count: two nobody has looked at yet.
    $entry($stage);
    $entry($stage);
    // Do not count: one verified, one rejected, one in a cancelled competition.
    $entry($stage, ['verified_at' => now(), 'verified_by_user_id' => (string) Str::ulid()]);
    $entry($stage, ['rejected_at' => now(), 'rejected_by_user_id' => (string) Str::ulid(), 'rejection_note' => 'Kein Beweis.']);
    $entry($oldStage);

    expect(competitionMetricsScrape($this))
        ->toContain('konventix_competition_score_entries_unverified{brand="'.BrandSlugs::of($brand->getKey()).'"} 2');
});

it('declares every Competition metric in the catalogue exactly once', function (): void {
    $declared = array_map(fn (MetricDefinition $d): string => $d->name, app(MetricCatalog::class)->all());

    expect(array_values(array_filter($declared, fn (string $name): bool => str_starts_with($name, 'competition_'))))
        ->toBe([
            'competition_entrants_total',
            'competition_matches_total',
            'competition_disputes_open',
            'competition_score_entries_unverified',
        ]);
});
