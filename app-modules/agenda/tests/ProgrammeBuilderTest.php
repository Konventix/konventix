<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;
use Konventix\Agenda\Programme\NowNext;
use Konventix\Agenda\Programme\ProgrammeBuilder;

/**
 * File-local, and prefixed `agenda` like everything in this module's
 * Helpers.php: Pest helper names are process-global across every module's
 * test files (.ai/rules/tests.md).
 *
 * @return array{day_id: string, date: string, label: string|null}
 */
function agendaDay(string $date, string $label = 'Freitag'): array
{
    return ['day_id' => (string) Str::ulid(), 'date' => $date, 'label' => $label];
}

it('makes a row per distinct time and spans an item across the rows it covers', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $stage = Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);
    $shop = Track::factory()->create(['event_id' => $eventId, 'name' => 'Workshops', 'position' => 2]);

    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Eröffnung', 'starts_at' => '2026-11-20T13:00:00Z', 'ends_at' => '2026-11-20T14:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $shop->id, 'state' => ItemState::Published,
        'title' => 'Lötkurs', 'starts_at' => '2026-11-20T13:15:00Z', 'ends_at' => null]);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin');

    // 14:00, 14:15, 15:00 local — three distinct times.
    expect($days)->toHaveCount(1)
        ->and($days[0]->trackNames)->toBe(['Bühne', 'Workshops'])
        ->and($days[0]->rows)->toHaveCount(3)
        ->and($days[0]->rows[0]->time)->toBe('14:00')
        ->and($days[0]->rows[0]->cells[0][0]->title)->toBe('Eröffnung')
        ->and($days[0]->rows[0]->cells[0][0]->rowSpan)->toBe(3)   // 14:00 → 15:00 spans the 14:15 row too
        ->and($days[0]->rows[1]->time)->toBe('14:15')
        ->and($days[0]->rows[1]->cells[1][0]->title)->toBe('Lötkurs')
        ->and($days[0]->rows[1]->cells[1][0]->rowSpan)->toBe(1);  // no end → one row
});

it('hides drafts, keeps cancelled items visible with their reason', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Draft,
        'title' => 'Geheim', 'starts_at' => '2026-11-20T13:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Cancelled,
        'title' => 'Fällt aus', 'cancelled_reason' => 'Referent krank', 'starts_at' => '2026-11-20T14:00:00Z']);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin');

    $titles = collect($days[0]->untracked)->pluck('title')->all();
    expect($titles)->toBe(['Fällt aus'])
        ->and($days[0]->untracked[0]->cancelled)->toBeTrue()
        ->and($days[0]->untracked[0]->cancelledReason)->toBe('Referent krank');
});

it('resolves a location name, falls back to the note, and tolerates neither', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $hall = Location::factory()->create(['event_id' => $eventId, 'name' => 'Halle 3']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'A', 'location_id' => $hall->id, 'starts_at' => '2026-11-20T13:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'B', 'location_note' => 'Parkplatz', 'starts_at' => '2026-11-20T13:30:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'C', 'starts_at' => '2026-11-20T14:00:00Z']);

    $cells = collect(app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin')[0]->untracked)
        ->keyBy('title');

    expect($cells['A']->location)->toBe('Halle 3')
        ->and($cells['B']->location)->toBe('Parkplatz')
        ->and($cells['C']->location)->toBeNull();
});

it('splits items across the days they fall on', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Freitag', 'starts_at' => '2026-11-20T13:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Samstag', 'starts_at' => '2026-11-21T13:00:00Z']);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id,
        [agendaDay('2026-11-20', 'Freitag'), agendaDay('2026-11-21', 'Samstag')], 'Europe/Berlin');

    expect($days)->toHaveCount(2)
        ->and($days[0]->untracked[0]->title)->toBe('Freitag')
        ->and($days[1]->untracked[0]->title)->toBe('Samstag');
});

it('answers what is running now and what starts next', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Läuft', 'starts_at' => '2026-11-20T13:00:00Z', 'ends_at' => '2026-11-20T15:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Gleich', 'starts_at' => '2026-11-20T16:00:00Z', 'ends_at' => null]);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Cancelled,
        'title' => 'Fällt aus', 'starts_at' => '2026-11-20T15:30:00Z']);

    $answer = app(NowNext::class)->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T14:00:00Z'));

    // A cancelled item is never "next" — it is not going to happen.
    expect($answer['now']?->title)->toBe('Läuft')
        ->and($answer['next']?->title)->toBe('Gleich');
});

it('omits a day that has no items', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Freitag', 'starts_at' => '2026-11-20T13:00:00Z']);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id, [
        agendaDay('2026-11-19', 'Donnerstag'),
        agendaDay('2026-11-20', 'Freitag'),
        agendaDay('2026-11-21', 'Samstag'),
    ], 'Europe/Berlin');

    expect($days)->toHaveCount(1)
        ->and($days[0]->label)->toBe('Freitag')
        ->and($days[0]->date)->toBe('2026-11-20');
});

it('names a day without a label after its place in the event, omitted days included', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Am zweiten Tag', 'starts_at' => '2026-11-21T13:00:00Z']);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id, [
        ['day_id' => (string) Str::ulid(), 'date' => '2026-11-20', 'label' => null],
        ['day_id' => (string) Str::ulid(), 'date' => '2026-11-21', 'label' => null],
    ], 'Europe/Berlin');

    expect($days)->toHaveCount(1)
        ->and($days[0]->label)->toBe('Tag 2');
});

it('puts an item on the day it falls on in the event timezone', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    // 23:30 UTC on the Friday is 00:30 on the Saturday in Berlin.
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Mitternachtsfilm', 'starts_at' => '2026-11-20T23:30:00Z', 'ends_at' => null]);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id,
        [agendaDay('2026-11-20', 'Freitag'), agendaDay('2026-11-21', 'Samstag')], 'Europe/Berlin');

    expect($days)->toHaveCount(1)
        ->and($days[0]->label)->toBe('Samstag')
        ->and($days[0]->rows[0]->time)->toBe('00:30')
        ->and($days[0]->untracked[0]->startsAt)->toBe('00:30');
});

it('spans an item that runs past midnight down to the day\'s last row', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $stage = Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);
    $tent = Track::factory()->create(['event_id' => $eventId, 'name' => 'Zelt', 'position' => 2]);

    // 21:00 → 02:00 the next morning, local.
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Nachtkonzert', 'starts_at' => '2026-11-20T20:00:00Z', 'ends_at' => '2026-11-21T01:00:00Z']);
    // 22:00 → 23:00 local.
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $tent->id, 'state' => ItemState::Published,
        'title' => 'Lesung', 'starts_at' => '2026-11-20T21:00:00Z', 'ends_at' => '2026-11-20T22:00:00Z']);

    $day = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin')[0];

    // The 02:00 end belongs to the next day and starts no row here.
    expect(collect($day->rows)->pluck('time')->all())->toBe(['21:00', '22:00', '23:00'])
        ->and($day->rows[0]->cells[0][0]->title)->toBe('Nachtkonzert')
        ->and($day->rows[0]->cells[0][0]->rowSpan)->toBe(3)
        ->and($day->rows[0]->cells[0][0]->endsAt)->toBe('02:00')
        ->and($day->rows[1]->cells[1][0]->title)->toBe('Lesung')
        ->and($day->rows[1]->cells[1][0]->rowSpan)->toBe(2);
});

it('puts an item with only an end in the row of that end', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $stage = Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);

    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Aufbau bis 08:00', 'starts_at' => null, 'ends_at' => '2026-11-20T07:00:00Z']);

    $day = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin')[0];

    expect($day->rows)->toHaveCount(1)
        ->and($day->rows[0]->time)->toBe('08:00')
        ->and($day->rows[0]->cells[0][0]->startsAt)->toBeNull()
        ->and($day->rows[0]->cells[0][0]->endsAt)->toBe('08:00')
        ->and($day->rows[0]->cells[0][0]->rowSpan)->toBe(1);
});

it('lets two items that begin together share one cell', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $stage = Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);

    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Band A', 'starts_at' => '2026-11-20T18:00:00Z', 'ends_at' => '2026-11-20T19:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Band B', 'starts_at' => '2026-11-20T18:00:00Z', 'ends_at' => '2026-11-20T19:00:00Z']);

    $day = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaDay('2026-11-20')], 'Europe/Berlin')[0];

    expect($day->rows[0]->cells[0])->toHaveCount(2)
        ->and(collect($day->rows[0]->cells[0])->pluck('title')->sort()->values()->all())
        ->toBe(['Band A', 'Band B']);
});

it('stops calling an open-ended item "now" once its two hours are up', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Einlass', 'starts_at' => '2026-11-20T13:30:00Z', 'ends_at' => null]);

    $builder = app(NowNext::class);
    $inside = $builder->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T15:00:00Z'));
    $after = $builder->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T16:00:00Z'));

    expect($inside['now']?->title)->toBe('Einlass')
        ->and($after['now'])->toBeNull()
        ->and($after['next'])->toBeNull();
});

it('announces the shorter item that began last as "now"', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'LAN-Party', 'starts_at' => '2026-11-20T10:00:00Z', 'ends_at' => '2026-11-20T22:00:00Z']);
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Vortrag', 'starts_at' => '2026-11-20T13:45:00Z', 'ends_at' => '2026-11-20T14:15:00Z']);

    $answer = app(NowNext::class)->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T14:00:00Z'));

    expect($answer['now']?->title)->toBe('Vortrag')
        ->and($answer['next'])->toBeNull();
});
