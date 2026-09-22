<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One programme entry as the public page draws it (design §5) — the
 * contents of a single grid cell, of an entry in the "Ohne Track" band,
 * or of the "Jetzt / Gleich" hub card.
 *
 * Everything here is already resolved for display, because the page must
 * not have to resolve anything:
 *
 * - `location` is the place's name, the free-text `location_note` when
 *   there is no row for it, and null when the item names no place at all.
 *   The page prints it or leaves it out; it never joins.
 * - `startsAt`/`endsAt` are wall-clock `H:i` in the event's timezone
 *   (ADR-0027), not instants. The three legal shapes of D-AGD-04 survive
 *   as they are: both ("14:00–15:00"), start only ("ab 12:00"), end only
 *   ("bis 08:00"). A block that runs past midnight keeps the *next* day's
 *   clock time in `endsAt` — "bis 02:00" is what a participant reads.
 * - `rowSpan` is how many rows of *this day's* grid the item covers, ready
 *   for the `rowspan` attribute of a plain HTML table; see
 *   `ProgrammeBuilder`. It is 1 wherever there is no grid — the untracked
 *   band and the hub card.
 * - `cancelled` and `cancelledReason` carry D-AGD-08: a called-off item
 *   stays on the page, struck through and with its reason, instead of
 *   quietly disappearing on somebody who planned an afternoon around it.
 */
#[TypeScript]
final class ProgrammeCellData extends Data
{
    public function __construct(
        public readonly string $itemId,
        public readonly string $title,
        public readonly ?string $speaker,
        public readonly ?string $location,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly int $rowSpan,
        public readonly bool $cancelled,
        public readonly ?string $cancelledReason,
    ) {}

    /**
     * Deliberately `ofItem` and not `fromItem`: laravel-data routes every
     * public static `from*` method through its magic creation, and this
     * one takes a timezone and a row span it could never be handed by a
     * cast (.ai/rules/data.md).
     *
     * `$item` is expected to have `location` eager-loaded — the builders
     * do that in their one query rather than joining per row.
     */
    public static function ofItem(AgendaItem $item, string $timezone, int $rowSpan = 1): self
    {
        // Not `$item->location?->name ?? ...`: Larastan mistypes a belongsTo
        // as never-null, which makes the nullsafe form a phpstan false
        // positive (`nullsafe.neverNull`) — an explicit null check sidesteps
        // it without changing the behaviour. `location_id` is nullable and
        // `nullOnDelete`, so the relation really does come back null.
        $location = $item->location;

        return new self(
            itemId: $item->id,
            title: $item->title,
            speaker: $item->speaker,
            location: $location !== null ? $location->name : $item->location_note,
            startsAt: $item->starts_at?->setTimezone($timezone)->format('H:i'),
            endsAt: $item->ends_at?->setTimezone($timezone)->format('H:i'),
            rowSpan: $rowSpan,
            cancelled: $item->state === ItemState::Cancelled,
            cancelledReason: $item->cancelled_reason,
        );
    }
}
