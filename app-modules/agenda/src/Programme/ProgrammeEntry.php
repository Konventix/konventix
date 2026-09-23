<?php

declare(strict_types=1);

namespace Konventix\Agenda\Programme;

use Carbon\CarbonImmutable;
use Konventix\Agenda\Data\ProgrammeCellData;
use Konventix\Agenda\Enums\CompetitionBlockState;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\ProgrammeCompetition;

/**
 * One thing that happens on the programme, whatever it came from — an
 * organizer's `AgendaItem`, or a competition the organizer selected
 * (timetable design §4.5) — reduced to what `ProgrammeBuilder` and
 * `NowNext` place it by: an id for the tie-break, a track, a start and an
 * end. Internal to this namespace; the page only ever sees the
 * `ProgrammeCellData` that `cell()` makes.
 *
 * A competition is an **open-ended block** (D-AGD-03): Competition owns
 * its start, nobody owns an end yet, so `endsAt` is null and every rule the
 * builder and `NowNext` already apply to "ab 12:00" applies to it
 * unchanged — one row in the grid, two hours of "now" on the hub card.
 *
 * Its track is the organizer's choice on the selection entry, not
 * anything Competition knows (D-AGD-02); a null or unknown track puts it
 * in the "Ohne Track" band like any untracked item.
 */
final readonly class ProgrammeEntry
{
    private function __construct(
        /** The item's ULID, or the competition's for a block. */
        public string $id,
        public ?string $trackId,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public bool $cancelled,
        private ?AgendaItem $item,
        private ?ProgrammeCompetition $competition,
        private ?string $url,
    ) {}

    /**
     * `$item` is expected to have `location` eager-loaded, which
     * `ProgrammeCellData::ofItem()` reads.
     */
    public static function ofItem(AgendaItem $item): self
    {
        return new self(
            id: $item->id,
            trackId: $item->track_id,
            startsAt: $item->starts_at,
            endsAt: $item->ends_at,
            cancelled: $item->state === ItemState::Cancelled,
            item: $item,
            competition: null,
            url: null,
        );
    }

    /**
     * `$url` is the competition page's, already decided by the caller
     * (`SelectedCompetitions::programmeEntries()`), or null for no link.
     */
    public static function ofCompetition(ProgrammeCompetition $competition, CompetitionEntry $entry, ?string $url): self
    {
        return new self(
            id: $competition->competition_id,
            trackId: $entry->track_id,
            startsAt: $competition->starts_at,
            endsAt: null,
            cancelled: $competition->state === CompetitionBlockState::Cancelled,
            item: null,
            competition: $competition,
            url: $url,
        );
    }

    public function cell(string $timezone, int $rowSpan): ProgrammeCellData
    {
        if ($this->item !== null) {
            return ProgrammeCellData::ofItem($this->item, $timezone, $rowSpan);
        }

        /** @var ProgrammeCompetition $competition one of the two is always set */
        $competition = $this->competition;

        return ProgrammeCellData::ofCompetition($competition, $timezone, $rowSpan, $this->url);
    }
}
