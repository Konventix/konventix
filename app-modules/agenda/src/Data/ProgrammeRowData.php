<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of one day's grid (design §5): a wall-clock time that actually
 * occurs on that day, and what stands in each track column at it.
 *
 * `cells` holds exactly one list per track, in the order of
 * `ProgrammeDayData::$trackNames` — the inner list is empty where that
 * track has nothing starting at this time, and holds more than one entry
 * where two items in one track begin together, which §5 says sit side by
 * side in the same cell. An item appears only in the row it *begins* in;
 * the rows it runs through are covered by its `rowSpan`.
 *
 * No `#[DataCollectionOf]` on `cells`, on purpose: that attribute marks a
 * property as a collection *of Data objects*, and laravel-data would then
 * try to transform each track's inner list as if the list itself were a
 * `ProgrammeCellData`. A list of lists is not a data collection.
 */
#[TypeScript]
final class ProgrammeRowData extends Data
{
    /**
     * @param  array<int, array<int, ProgrammeCellData>>  $cells  one list per track, in track order
     */
    public function __construct(
        public readonly string $time,

        /** @var array<int, array<int, ProgrammeCellData>> */
        public readonly array $cells,
    ) {}
}
