<?php

declare(strict_types=1);

namespace Konventix\Agenda\Programme;

use Carbon\CarbonImmutable;
use Konventix\Agenda\Data\ProgrammeCellData;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;

/**
 * Answers the two questions a participant standing in a hall actually has
 * — what is running *now*, what starts *next* — for the "Jetzt / Gleich"
 * hub card (design §6).
 *
 * **Only published items.** `AgendaItem::scopeVisible()` is published *and*
 * cancelled, because D-AGD-08 keeps a called-off item on the programme
 * page, struck through, so that somebody who planned an afternoon around
 * it is told. That is the grid's job, not this card's: a cancelled item is
 * never `now` and never `next`, because it is not going to happen, and
 * announcing it as the next thing would be worse than saying nothing. So
 * this asks for `Published` alone.
 *
 * **An open-ended item runs for two hours.** D-AGD-04 allows an item with
 * a start and no end — "Einlass ab 12:00", "Bar offen ab 20:00". Taken
 * literally it never finishes, and the card would still announce the
 * Friday doors on Sunday afternoon. It is also not free to invent an end,
 * because the organizer deliberately did not give one. Two hours is the
 * compromise: long enough that a talk somebody forgot to close is still
 * "now" while it is really running, short enough that a stale entry falls
 * off the card within the same block of the day. It is a display rule
 * only — nothing is written, and the grid still shows the item as the
 * single open-ended row it is. `OPEN_ENDED_RUNS_FOR_MINUTES` is the one
 * place to change it.
 *
 * **An item with only an end is neither.** "bis 08:00" says when something
 * stops and never when it began, so there is no honest moment at which to
 * call it running and nothing about it ever starts.
 *
 * **When several items are running, the one that began last wins.** A
 * half-hour talk inside a four-hour LAN block is what a participant wants
 * to be told about; the block is the background.
 *
 * Times come out in the timezone of `$at`: the caller hands over the
 * event's own current wall clock (ADR-0027), and the card shows the same
 * clock as the programme page.
 */
final class NowNext
{
    /**
     * How long an item with a start and no end counts as running.
     */
    public const OPEN_ENDED_RUNS_FOR_MINUTES = 120;

    /**
     * @return array{now: ?ProgrammeCellData, next: ?ProgrammeCellData}
     */
    public function forEvent(string $eventId, string $brandId, CarbonImmutable $at): array
    {
        $items = AgendaItem::query()
            ->forBrand($brandId)
            ->where('event_id', $eventId)
            ->where('state', ItemState::Published)
            ->whereNotNull('starts_at')
            ->with(['location'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $openEndedSince = $at->subMinutes(self::OPEN_ENDED_RUNS_FOR_MINUTES);

        $now = null;
        $next = null;

        foreach ($items as $item) {
            $start = $item->starts_at;

            if ($start === null) {
                continue;
            }

            if ($start->greaterThan($at)) {
                // Ordered by start, so the first one still ahead is the next one.
                $next ??= $item;

                continue;
            }

            $end = $item->ends_at;
            $running = $end !== null
                ? $end->greaterThan($at)
                : $start->greaterThan($openEndedSince);

            if ($running) {
                // Same ordering: the last match is the latest to have begun.
                $now = $item;
            }
        }

        $timezone = $at->getTimezone()->getName();

        return [
            'now' => $now === null ? null : ProgrammeCellData::ofItem($now, $timezone),
            'next' => $next === null ? null : ProgrammeCellData::ofItem($next, $timezone),
        ];
    }
}
