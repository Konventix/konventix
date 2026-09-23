<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Enums\ProgrammeEntryKind;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\Track;
use Konventix\Agenda\Programme\NowNext;
use Konventix\Agenda\Programme\ProgrammeBuilder;
use Konventix\Competition\Events\CompetitionCancelled;
use Konventix\Competition\Events\CompetitionUnpublished;

/**
 * Selected competitions in the programme and on the "Jetzt / Gleich" card
 * (timetable design §4.5, §5, §6, D-AGD-03): an open-ended block at its
 * Competition-owned start, in the track the organizer gave the selection.
 *
 * These live apart from `ProgrammeBuilderTest` on purpose: that file pins
 * the item-only behaviour the refactor onto `ProgrammeEntry` had to keep,
 * and it stays byte-for-byte as it was.
 *
 * Every competition reaches Agenda through Competition's contract events
 * (`agendaPublishedCompetition()`), never as a hand-written row. Every
 * wall clock below is Europe/Berlin in November, which is CET = UTC+1.
 */

/**
 * File-local day, named apart from `ProgrammeBuilderTest`'s `agendaDay()`:
 * Pest helper names are process-global (.ai/rules/tests.md).
 *
 * @return array{day_id: string, date: string, label: string|null}
 */
function agendaBlocksDay(string $date, string $label = 'Freitag'): array
{
    return ['day_id' => (string) Str::ulid(), 'date' => $date, 'label' => $label];
}

function agendaSelect(string $eventId, string $competitionId, ?string $trackId = null, int $position = 0): CompetitionEntry
{
    return CompetitionEntry::factory()->forEvent($eventId)->create([
        'competition_id' => $competitionId,
        'track_id' => $trackId,
        'position' => $position,
    ]);
}

it('places a selected competition as an open-ended block in its entry\'s track', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);
    $arena = Track::factory()->create(['event_id' => $eventId, 'name' => 'Arena', 'position' => 2]);

    // 13:30 UTC is 14:30 in Berlin (CET, UTC+1).
    $competitionId = agendaPublishedCompetition($brand, $eventId, ['startsAt' => '2026-11-20T13:30:00Z']);
    agendaSelect($eventId, $competitionId, $arena->id);

    $days = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin');

    // One time occurs on the day — the block's 14:30 start; with no end
    // there is no second boundary, so exactly one row. Arena is column 1
    // (Bühne, position 1, is column 0).
    expect($days)->toHaveCount(1)
        ->and($days[0]->trackNames)->toBe(['Bühne', 'Arena'])
        ->and($days[0]->rows)->toHaveCount(1)
        ->and($days[0]->rows[0]->time)->toBe('14:30')
        ->and($days[0]->rows[0]->cells[0])->toBe([])
        ->and($days[0]->rows[0]->cells[1])->toHaveCount(1)
        ->and($days[0]->untracked)->toBe([]);

    $block = $days[0]->rows[0]->cells[1][0];

    expect($block->kind)->toBe(ProgrammeEntryKind::Competition)
        ->and($block->itemId)->toBe($competitionId)
        ->and($block->title)->toBe('Rocket League 2v2')
        ->and($block->startsAt)->toBe('14:30')
        // D-AGD-03: Competition owns the start, nobody an end yet.
        ->and($block->endsAt)->toBeNull()
        ->and($block->rowSpan)->toBe(1)
        ->and($block->speaker)->toBeNull()
        ->and($block->location)->toBeNull()
        ->and($block->cancelled)->toBeFalse();
});

it('adds a competition\'s start as a row time between two items', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $stage = Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne', 'position' => 1]);
    $arena = Track::factory()->create(['event_id' => $eventId, 'name' => 'Arena', 'position' => 2]);

    // 13:00–15:00 UTC is 14:00–16:00 local.
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Eröffnung', 'starts_at' => '2026-11-20T13:00:00Z', 'ends_at' => '2026-11-20T15:00:00Z']);
    // 16:00 UTC is 17:00 local, open-ended.
    AgendaItem::factory()->create(['event_id' => $eventId, 'track_id' => $stage->id, 'state' => ItemState::Published,
        'title' => 'Konzert', 'starts_at' => '2026-11-20T16:00:00Z', 'ends_at' => null]);
    // 13:30 UTC is 14:30 local.
    $competitionId = agendaPublishedCompetition($brand, $eventId, ['startsAt' => '2026-11-20T13:30:00Z']);
    agendaSelect($eventId, $competitionId, $arena->id);

    $day = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin')[0];

    // Rows: 14:00 (Eröffnung starts), 14:30 (the block), 16:00 (Eröffnung
    // ends), 17:00 (Konzert). Eröffnung spans [14:00, 16:00): rows 0 and 1,
    // closing on row 2, so 2 - 0 = 2. The block sits in row 1, Arena's
    // column 1, one row. Konzert has no end: row 3, one row.
    expect(collect($day->rows)->pluck('time')->all())->toBe(['14:00', '14:30', '16:00', '17:00'])
        ->and($day->rows[0]->cells[0][0]->title)->toBe('Eröffnung')
        ->and($day->rows[0]->cells[0][0]->rowSpan)->toBe(2)
        ->and($day->rows[0]->cells[0][0]->kind)->toBe(ProgrammeEntryKind::Item)
        ->and($day->rows[1]->cells[1][0]->itemId)->toBe($competitionId)
        ->and($day->rows[1]->cells[1][0]->kind)->toBe(ProgrammeEntryKind::Competition)
        ->and($day->rows[1]->cells[1][0]->rowSpan)->toBe(1)
        ->and($day->rows[3]->cells[0][0]->title)->toBe('Konzert')
        ->and($day->rows[3]->cells[0][0]->rowSpan)->toBe(1);
});

it('leaves out a published competition nobody selected', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    // 13:00 UTC is 14:00 local.
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Eröffnung', 'starts_at' => '2026-11-20T13:00:00Z']);
    // Published and known to Agenda, but not in the organizer's selection.
    agendaPublishedCompetition($brand, $eventId, ['startsAt' => '2026-11-20T13:30:00Z']);

    $day = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin')[0];

    // Only the item's 14:00: the competition's 14:30 contributes no row.
    expect(collect($day->rows)->pluck('time')->all())->toBe(['14:00'])
        ->and(collect($day->untracked)->pluck('title')->all())->toBe(['Eröffnung']);
});

it('leaves out a selected competition with no start time', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Eröffnung', 'starts_at' => '2026-11-20T13:00:00Z']);
    $competitionId = agendaPublishedCompetition($brand, $eventId, ['startsAt' => null]);
    agendaSelect($eventId, $competitionId);

    $days = app(ProgrammeBuilder::class)->forEvent($eventId, $brand->id,
        [agendaBlocksDay('2026-11-20', 'Freitag'), agendaBlocksDay('2026-11-21', 'Samstag')], 'Europe/Berlin');

    // No anchor, so no day and no row — not even the untracked band, which
    // is per day as well. Only Freitag survives, and only with the item.
    expect($days)->toHaveCount(1)
        ->and(collect($days[0]->untracked)->pluck('title')->all())->toBe(['Eröffnung'])
        ->and(collect($days[0]->rows)->pluck('time')->all())->toBe(['14:00']);
});

it('keeps a cancelled competition on the page, struck, and never calls it now or next', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    // 13:00 UTC is 14:00 local.
    $competitionId = agendaPublishedCompetition($brand, $eventId, ['startsAt' => '2026-11-20T13:00:00Z']);
    agendaSelect($eventId, $competitionId);

    event(new CompetitionCancelled(
        competitionId: $competitionId,
        eventId: $eventId,
        disciplineId: (string) Str::ulid(),
        brandId: $brand->getKey(),
        status: 'cancelled',
        at: '2026-10-01T09:00:00+00:00',
    ));

    $day = app(ProgrammeBuilder::class)
        ->forEvent($eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin')[0];

    // D-AGD-08 applies to a block as to an item: still there, struck. No
    // reason, because Competition sends none.
    expect($day->untracked)->toHaveCount(1)
        ->and($day->untracked[0]->itemId)->toBe($competitionId)
        ->and($day->untracked[0]->kind)->toBe(ProgrammeEntryKind::Competition)
        ->and($day->untracked[0]->startsAt)->toBe('14:00')
        ->and($day->untracked[0]->cancelled)->toBeTrue()
        ->and($day->untracked[0]->cancelledReason)->toBeNull();

    $nowNext = app(NowNext::class);
    // 12:00 UTC (13:00 local) is before the 14:00 start: it would be "next".
    $before = $nowNext->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T13:00:00', 'Europe/Berlin'));
    // 13:30 UTC (14:30 local) is inside its two open-ended hours: it would be "now".
    $during = $nowNext->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T14:30:00', 'Europe/Berlin'));

    expect($before['next'])->toBeNull()
        ->and($before['now'])->toBeNull()
        ->and($during['now'])->toBeNull()
        ->and($during['next'])->toBeNull();
});

it('drops the block once Competition unpublishes it', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competitionId = agendaPublishedCompetition($brand, $eventId, ['startsAt' => '2026-11-20T13:00:00Z']);
    $entry = agendaSelect($eventId, $competitionId);

    $builder = app(ProgrammeBuilder::class);
    $before = $builder->forEvent($eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin');

    expect($before)->toHaveCount(1)
        ->and($before[0]->untracked[0]->itemId)->toBe($competitionId);

    event(new CompetitionUnpublished(
        competitionId: $competitionId,
        eventId: $eventId,
        brandId: $brand->getKey(),
        unpublishedAt: '2026-10-01T09:00:00+00:00',
    ));

    // The block was the day's only entry, so the day goes with it — while
    // the organizer's selection stays, for a republish to restore.
    expect($builder->forEvent($eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin'))->toBe([])
        ->and(CompetitionEntry::withoutBrandScope()->whereKey($entry->id)->exists())->toBeTrue();
});

it('links a block to the competition page only while competition is on for the brand', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Eröffnung', 'starts_at' => '2026-11-20T12:00:00Z']);
    $competitionId = agendaPublishedCompetition($brand, $eventId, [
        'slug' => 'rocket-league-2v2',
        'startsAt' => '2026-11-20T13:00:00Z',
    ]);
    agendaSelect($eventId, $competitionId);

    $builder = app(ProgrammeBuilder::class);
    $blockOf = fn (?string $eventSlug) => collect($builder->forEvent(
        $eventId, $brand->id, [agendaBlocksDay('2026-11-20')], 'Europe/Berlin', $eventSlug,
    )[0]->untracked)->keyBy('title');

    // Modules default to off (ADR-0003): a link now would lead to a 404.
    $off = $blockOf('agenda-testcon');

    $this->activateModule('competition', $brand);

    $on = $blockOf('agenda-testcon');
    // On, but the caller knows no event slug to build the path under.
    $noSlug = $blockOf(null);

    expect($off['Rocket League 2v2']->url)->toBeNull()
        ->and($on['Rocket League 2v2']->url)->toBe('/events/agenda-testcon/tournaments/rocket-league-2v2')
        ->and($noSlug['Rocket League 2v2']->url)->toBeNull()
        // An item never links.
        ->and($on['Eröffnung']->url)->toBeNull()
        ->and($on['Eröffnung']->kind)->toBe(ProgrammeEntryKind::Item);
});

it('answers now and next across items and competitions', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    // 14:00–17:00 local is 13:00–16:00 UTC.
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'LAN-Party', 'starts_at' => '2026-11-20T13:00:00Z', 'ends_at' => '2026-11-20T16:00:00Z']);
    // 15:00 local is 14:00 UTC; open-ended, like every block.
    $competitionId = agendaPublishedCompetition($brand, $eventId, ['startsAt' => '2026-11-20T14:00:00Z']);
    agendaSelect($eventId, $competitionId);
    // 16:00 local is 15:00 UTC.
    AgendaItem::factory()->create(['event_id' => $eventId, 'state' => ItemState::Published,
        'title' => 'Siegerehrung', 'starts_at' => '2026-11-20T15:00:00Z', 'ends_at' => null]);

    // Asked at 15:30 local (14:30 UTC). LAN-Party runs until 16:00 UTC, so
    // it is running. The block began at 14:00 UTC, 30 minutes ago — inside
    // its 120 open-ended minutes (window opens 12:30 UTC) — so it is
    // running too. The later start wins: the block (15:00) over the party
    // (14:00). Siegerehrung at 15:00 UTC is the first start still ahead.
    $answer = app(NowNext::class)->forEvent($eventId, $brand->id, CarbonImmutable::parse('2026-11-20T15:30:00', 'Europe/Berlin'));

    expect($answer['now']?->kind)->toBe(ProgrammeEntryKind::Competition)
        ->and($answer['now']?->itemId)->toBe($competitionId)
        // The clock of `$at`, which is the event's: 14:00 UTC is 15:00.
        ->and($answer['now']?->startsAt)->toBe('15:00')
        ->and($answer['now']?->endsAt)->toBeNull()
        ->and($answer['next']?->title)->toBe('Siegerehrung')
        ->and($answer['next']?->kind)->toBe(ProgrammeEntryKind::Item);
});
