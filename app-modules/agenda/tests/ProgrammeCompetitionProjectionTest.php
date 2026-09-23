<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Konventix\Agenda\Enums\CompetitionBlockState;
use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\ProgrammeCompetition;
use Konventix\Agenda\Models\Track;
use Konventix\Competition\Events\CompetitionAnnounced;
use Konventix\Competition\Events\CompetitionCancelled;
use Konventix\Competition\Events\CompetitionRescheduled;
use Konventix\Competition\Events\CompetitionUnpublished;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Operator;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Operators\OperatorContext;

/**
 * `agenda_competitions` is Agenda's read model of Competition's announced
 * tournaments (timetable design §4.5, D-AGD-03), and
 * `agenda_competition_entries` the organizer's selection (D-AGD-02). The
 * projection is fed only by Competition's contract events, so every test
 * here emits them rather than writing rows.
 *
 * Every read goes through `withoutBrandScope()`: the listener names the
 * brand from the payload, and the assertions check exactly that.
 */
function agendaProjectedCompetition(string $competitionId): ?ProgrammeCompetition
{
    return ProgrammeCompetition::withoutBrandScope()->where('competition_id', $competitionId)->first();
}

it('projects a published competition with its name, slug and Competition-owned start', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $competitionId = agendaPublishedCompetition($brand, $eventId, [
        'name' => 'CS2 5v5',
        'slug' => 'cs2-5v5',
        'startsAt' => '2026-11-20T14:00:00+01:00',
    ]);

    $row = agendaProjectedCompetition($competitionId);

    expect($row)->not->toBeNull()
        ->and($row->brand_id)->toBe($brand->getKey())
        ->and($row->event_id)->toBe($eventId)
        ->and($row->name)->toBe('CS2 5v5')
        ->and($row->slug)->toBe('cs2-5v5')
        ->and($row->state)->toBe(CompetitionBlockState::Published)
        // 14:00 at +01:00 is 13:00 UTC.
        ->and($row->starts_at?->utc()->toIso8601String())->toBe('2026-11-20T13:00:00+00:00');
});

it('projects a published competition without a start as an unscheduled block', function (): void {
    $brand = $this->withBrand();

    $competitionId = agendaPublishedCompetition($brand, (string) Str::ulid(), ['startsAt' => null]);

    $row = agendaProjectedCompetition($competitionId);

    // The row first: without it the null start below would also hold with
    // the listener unregistered, where `?->` turns a missing row into null.
    expect($row)->not->toBeNull()
        ->and($row?->starts_at)->toBeNull();
});

it('moves the block when Competition reschedules it, and ignores a reschedule it never saw published', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = agendaPublishedCompetition($brand, $eventId);

    event(new CompetitionRescheduled(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        previousStartsAt: '2026-11-20T14:00:00+01:00',
        startsAt: '2026-11-21T10:30:00+01:00',
        at: '2026-10-01T09:00:00+00:00',
    ));

    $unknownId = (string) Str::ulid();
    event(new CompetitionRescheduled(
        competitionId: $unknownId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        previousStartsAt: null,
        startsAt: '2026-11-21T12:00:00+01:00',
        at: '2026-10-01T09:00:00+00:00',
    ));

    // 10:30 at +01:00 is 09:30 UTC.
    expect(agendaProjectedCompetition($competitionId)?->starts_at?->utc()->toIso8601String())->toBe('2026-11-21T09:30:00+00:00')
        // The reschedule carries no name, so nothing is created from it.
        ->and(agendaProjectedCompetition($unknownId))->toBeNull()
        ->and(ProgrammeCompetition::withoutBrandScope()->count())->toBe(1);
});

it('strikes a cancelled competition and keeps the row', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = agendaPublishedCompetition($brand, $eventId);

    event(new CompetitionCancelled(
        competitionId: $competitionId,
        eventId: $eventId,
        disciplineId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        status: 'cancelled',
        at: '2026-10-01T09:00:00+00:00',
    ));

    $row = agendaProjectedCompetition($competitionId);

    expect($row)->not->toBeNull()
        ->and($row->state)->toBe(CompetitionBlockState::Cancelled)
        ->and($row->name)->toBe('Rocket League 2v2')
        // The helper's default start, 14:00 at +01:00, untouched by the cancel.
        ->and($row->starts_at?->utc()->toIso8601String())->toBe('2026-11-20T13:00:00+00:00');
});

it('ignores a cancel of a competition it never saw', function (): void {
    $brand = $this->withBrand();

    // Competition cancels straight from draft too; such a competition was
    // never announced and has no block to strike.
    event(new CompetitionCancelled(
        competitionId: (string) Str::ulid(),
        eventId: (string) Str::ulid(),
        disciplineId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        status: 'cancelled',
        at: '2026-10-01T09:00:00+00:00',
    ));

    expect(ProgrammeCompetition::withoutBrandScope()->count())->toBe(0);
});

it('removes the row on CompetitionUnpublished and keeps the organizer\'s selection', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = agendaPublishedCompetition($brand, $eventId);
    $track = Track::factory()->forEvent($eventId)->create(['name' => 'Turniere']);
    $entry = CompetitionEntry::factory()->forEvent($eventId)->create([
        'competition_id' => $competitionId,
        'track_id' => $track->id,
        'position' => 2,
    ]);

    event(new CompetitionUnpublished(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        unpublishedAt: '2026-10-01T09:00:00+00:00',
    ));

    $kept = CompetitionEntry::withoutBrandScope()->find($entry->id);

    expect(agendaProjectedCompetition($competitionId))->toBeNull()
        ->and($kept)->not->toBeNull()
        ->and($kept->competition_id)->toBe($competitionId)
        ->and($kept->track_id)->toBe($track->id)
        ->and($kept->position)->toBe(2);
});

it('brings the block back when the competition is published again', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = agendaPublishedCompetition($brand, $eventId);
    $track = Track::factory()->forEvent($eventId)->create(['name' => 'Turniere']);
    CompetitionEntry::factory()->forEvent($eventId)->create([
        'competition_id' => $competitionId,
        'track_id' => $track->id,
        'position' => 1,
    ]);

    event(new CompetitionUnpublished(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        unpublishedAt: '2026-10-01T09:00:00+00:00',
    ));

    // Republished with a new start: 16:00 at +01:00.
    agendaPublishedCompetition($brand, $eventId, [
        'competitionId' => $competitionId,
        'startsAt' => '2026-11-20T16:00:00+01:00',
    ]);

    $row = agendaProjectedCompetition($competitionId);
    $entry = CompetitionEntry::withoutBrandScope()->where('competition_id', $competitionId)->sole();

    expect($row)->not->toBeNull()
        ->and($row->state)->toBe(CompetitionBlockState::Published)
        // 16:00 at +01:00 is 15:00 UTC.
        ->and($row->starts_at?->utc()->toIso8601String())->toBe('2026-11-20T15:00:00+00:00')
        // The selection is where the organizer put it.
        ->and($entry->track_id)->toBe($track->id)
        ->and($entry->position)->toBe(1);
});

it('backfills from CompetitionAnnounced and unlearns a draft', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $announce = fn (string $competitionId, string $status, bool $announced, ?string $startsAt = '2026-11-21T11:00:00+01:00'): mixed => event(new CompetitionAnnounced(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        name: 'Mario Kart',
        slug: 'mario-kart',
        status: $status,
        startsAt: $startsAt,
        announced: $announced,
    ));

    // Published before Agenda existed: learned from the backfill alone.
    $running = (string) Str::ulid();
    $announce($running, 'signup_closed', true);

    // Cancelled after publishing: learned, and struck.
    $struck = (string) Str::ulid();
    $announce($struck, 'cancelled', true);

    // Cancelled straight from draft: never announced, never learned.
    $neverSeen = (string) Str::ulid();
    $announce($neverSeen, 'cancelled', false);

    // Projected earlier, unpublished since (the missed event): unlearned.
    $unpublished = agendaPublishedCompetition($brand, $eventId);
    $announce($unpublished, 'draft', false);

    $row = agendaProjectedCompetition($running);

    expect($row)->not->toBeNull()
        ->and($row->brand_id)->toBe($brand->getKey())
        ->and($row->event_id)->toBe($eventId)
        ->and($row->name)->toBe('Mario Kart')
        ->and($row->slug)->toBe('mario-kart')
        // `signup_closed` is a standing competition: the block stands.
        ->and($row->state)->toBe(CompetitionBlockState::Published)
        // 11:00 at +01:00 is 10:00 UTC.
        ->and($row->starts_at?->utc()->toIso8601String())->toBe('2026-11-21T10:00:00+00:00')
        ->and(agendaProjectedCompetition($struck)?->state)->toBe(CompetitionBlockState::Cancelled)
        ->and(agendaProjectedCompetition($neverSeen))->toBeNull()
        ->and(agendaProjectedCompetition($unpublished))->toBeNull()
        // $running and $struck.
        ->and(ProgrammeCompetition::withoutBrandScope()->count())->toBe(2);
});

it('replays idempotently', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = (string) Str::ulid();

    $snapshot = fn (): array => ProgrammeCompetition::withoutBrandScope()
        ->get()
        ->map(fn (ProgrammeCompetition $row): array => [
            'id' => $row->id,
            'brand_id' => $row->brand_id,
            'event_id' => $row->event_id,
            'competition_id' => $row->competition_id,
            'name' => $row->name,
            'slug' => $row->slug,
            'starts_at' => $row->starts_at?->utc()->toIso8601String(),
            'state' => $row->state->value,
        ])
        ->all();

    $twice = function (callable $deliver) use ($snapshot): array {
        $deliver();
        $first = $snapshot();
        $deliver();

        expect($snapshot())->toBe($first);

        return $first;
    };

    $published = $twice(fn () => agendaPublishedCompetition($brand, $eventId, ['competitionId' => $competitionId]));

    expect($published)->toHaveCount(1)
        // The helper's default start, 14:00 at +01:00, is 13:00 UTC.
        ->and($published[0]['starts_at'])->toBe('2026-11-20T13:00:00+00:00');

    $rescheduled = $twice(fn () => event(new CompetitionRescheduled(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        previousStartsAt: '2026-11-20T14:00:00+01:00',
        startsAt: '2026-11-20T18:00:00+01:00',
        at: '2026-10-01T09:00:00+00:00',
    )));

    // 18:00 at +01:00 is 17:00 UTC; the row keeps its id across both deliveries.
    expect($rescheduled[0]['starts_at'])->toBe('2026-11-20T17:00:00+00:00')
        ->and($rescheduled[0]['id'])->toBe($published[0]['id']);

    $announced = $twice(fn () => event(new CompetitionAnnounced(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        name: 'Rocket League 2v2',
        slug: 'rocket-league-2v2',
        status: 'published',
        startsAt: '2026-11-20T18:00:00+01:00',
        announced: true,
    )));

    // The backfill restates what the lifecycle already told: nothing moves.
    expect($announced)->toBe($rescheduled);

    $cancelled = $twice(fn () => event(new CompetitionCancelled(
        competitionId: $competitionId,
        eventId: $eventId,
        disciplineId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        status: 'cancelled',
        at: '2026-10-02T09:00:00+00:00',
    )));

    expect($cancelled)->toHaveCount(1)
        ->and($cancelled[0]['state'])->toBe('cancelled');

    $unpublished = $twice(fn () => event(new CompetitionUnpublished(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        unpublishedAt: '2026-10-03T09:00:00+00:00',
    )));

    expect($unpublished)->toBe([]);

    $unlearned = $twice(fn () => event(new CompetitionAnnounced(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        name: 'Rocket League 2v2',
        slug: 'rocket-league-2v2',
        status: 'draft',
        startsAt: null,
        announced: false,
    )));

    expect($unlearned)->toBe([]);
});

it('projects while agenda is off for the brand', function (): void {
    $brand = $this->withBrand();

    // Modules default to off (ADR-0003) and nothing here switches Agenda on.
    expect(app(ModuleGate::class)->isActiveForBrand('agenda', $brand->getKey()))->toBeFalse();

    $competitionId = agendaPublishedCompetition($brand, (string) Str::ulid());

    // Decision 1 of the plan: a brand switching Agenda on later must find
    // its tournaments selectable without a backfill.
    expect(agendaProjectedCompetition($competitionId))->not->toBeNull();
});

it('writes from the operator admin host, where the brand scope is empty', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    // An admin-host request, e.g. an operator console action (ADR-0029): an
    // operator and no brand, so `BrandScope` hardens to 1 = 0. A plain
    // console (artisan, a queued job) is a different case — no brand and no
    // operator, where the scope is unconstrained rather than empty.
    app(BrandContext::class)->forget();
    app(OperatorContext::class)->set(Operator::query()->findOrFail($brand->operator_id));

    $competitionId = agendaPublishedCompetition($brand, $eventId);

    event(new CompetitionRescheduled(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        previousStartsAt: '2026-11-20T14:00:00+01:00',
        startsAt: '2026-11-20T15:00:00+01:00',
        at: '2026-10-01T09:00:00+00:00',
    ));

    event(new CompetitionCancelled(
        competitionId: $competitionId,
        eventId: $eventId,
        disciplineId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        status: 'cancelled',
        at: '2026-10-01T09:05:00+00:00',
    ));

    $row = agendaProjectedCompetition($competitionId);

    // The scope really is hardened here: an ambient-brand read sees nothing…
    expect(ProgrammeCompetition::query()->count())->toBe(0)
        // …while the projection wrote, rescheduled and struck the row anyway.
        ->and($row)->not->toBeNull()
        ->and($row->brand_id)->toBe($brand->getKey())
        // 15:00 at +01:00 is 14:00 UTC.
        ->and($row->starts_at?->utc()->toIso8601String())->toBe('2026-11-20T14:00:00+00:00')
        ->and($row->state)->toBe(CompetitionBlockState::Cancelled);

    event(new CompetitionUnpublished(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        unpublishedAt: '2026-10-01T09:10:00+00:00',
    ));

    expect(agendaProjectedCompetition($competitionId))->toBeNull();
});

it('refuses the same competition twice in one event\'s selection', function (): void {
    $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = (string) Str::ulid();

    CompetitionEntry::factory()->forEvent($eventId)->create(['competition_id' => $competitionId]);

    expect(fn () => CompetitionEntry::factory()->forEvent($eventId)->create(['competition_id' => $competitionId]))
        ->toThrow(QueryException::class);
});

it('leaves a selection untracked when its track is deleted', function (): void {
    $this->withBrand();
    $eventId = (string) Str::ulid();
    $track = Track::factory()->forEvent($eventId)->create();
    $entry = CompetitionEntry::factory()->forEvent($eventId)->create(['track_id' => $track->id, 'position' => 3]);

    $track->delete();

    expect($entry->fresh()?->track_id)->toBeNull()
        ->and($entry->fresh()?->position)->toBe(3);
});
