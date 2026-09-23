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
 * **Selected competitions count like items without an end** (§4.5,
 * D-AGD-03): a block with a Competition-owned start is "now" for the same
 * two hours, is "next" when it is the first thing still ahead, and loses
 * to a later-begun item exactly as an item would. A cancelled block is
 * never either; one with no start has no moment to be either at.
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
     * @param  string|null  $eventSlug  the event's public slug; without it a competition block has no link
     * @return array{now: ?ProgrammeCellData, next: ?ProgrammeCellData}
     */
    public function forEvent(string $eventId, string $brandId, CarbonImmutable $at, ?string $eventSlug = null): array
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

        $entries = [
            ...$items->map(fn (AgendaItem $item): ProgrammeEntry => ProgrammeEntry::ofItem($item))->all(),
            ...array_filter(
                SelectedCompetitions::programmeEntries($eventId, $brandId, $eventSlug),
                static fn (ProgrammeEntry $entry): bool => ! $entry->cancelled,
            ),
        ];

        // The loop below relies on start order, the id breaking a tie the
        // way the query's `orderBy('id')` did for items alone. Every entry
        // here has a start: the item query demands one, and
        // `programmeEntries()` drops a competition without one.
        usort($entries, static fn (ProgrammeEntry $a, ProgrammeEntry $b): int => [$a->startsAt?->getTimestamp(), $a->id]
            <=> [$b->startsAt?->getTimestamp(), $b->id]);

        $openEndedSince = $at->subMinutes(self::OPEN_ENDED_RUNS_FOR_MINUTES);

        $now = null;
        $next = null;

        foreach ($entries as $item) {
            $start = $item->startsAt;

            if ($start === null) {
                continue;
            }

            if ($start->greaterThan($at)) {
                // Ordered by start, so the first one still ahead is the next one.
                $next ??= $item;

                continue;
            }

            $end = $item->endsAt;
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
            'now' => $now?->cell($timezone, 1),
            'next' => $next?->cell($timezone, 1),
        ];
    }
}
