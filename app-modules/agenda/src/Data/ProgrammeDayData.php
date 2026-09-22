<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One event day of the public programme (design §5): one grid, plus the
 * full-width "Ohne Track" band above it.
 *
 * `dayId`, `label` and `date` are what the day switcher is built from —
 * they come from the caller's `event_days` row, because Agenda reads no
 * other module's tables (ADR-0004). `date` is the day in the event's
 * timezone, `YYYY-MM-DD`.
 *
 * `trackNames` are the grid's columns in `position` order and are the same
 * for every day of the event, so that a track used only on Saturday still
 * keeps its column on Friday rather than shifting every other column
 * sideways between days. `rows[].cells` is indexed against exactly this
 * list.
 *
 * `untracked` holds the items with no track, in time order — §5's band,
 * which is also where an all-day item belongs. A day with no items at all
 * is not built: `ProgrammeBuilder` omits it rather than offering the
 * switcher an empty grid.
 */
#[TypeScript]
final class ProgrammeDayData extends Data
{
    /**
     * @param  array<int, string>  $trackNames
     * @param  array<int, ProgrammeRowData>  $rows
     * @param  array<int, ProgrammeCellData>  $untracked
     */
    public function __construct(
        public readonly string $dayId,
        public readonly string $label,
        public readonly string $date,

        /** @var array<int, string> */
        public readonly array $trackNames,

        /** @var array<int, ProgrammeRowData> */
        #[DataCollectionOf(ProgrammeRowData::class)]
        public readonly array $rows,

        /** @var array<int, ProgrammeCellData> */
        #[DataCollectionOf(ProgrammeCellData::class)]
        public readonly array $untracked,
    ) {}
}
