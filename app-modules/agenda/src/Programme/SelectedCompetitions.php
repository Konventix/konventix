<?php

declare(strict_types=1);

namespace Konventix\Agenda\Programme;

use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\ProgrammeCompetition;

/**
 * The competitions an event's programme shows (timetable design §4.5,
 * D-AGD-02): the organizer's selection, each entry paired with the read
 * model's row for its competition.
 *
 * An entry whose competition has no row is dropped. That is the unpublished
 * case — Competition withdrew the tournament, the projection deleted its
 * row, and the organizer's selection survives so that republishing restores
 * the block where it was — and the programme shows nothing for it.
 *
 * Two queries, paired in PHP: the entries, then the read-model rows of just
 * those competitions. `competition_id` carries no FK (see the entries'
 * migration), so there is no relation to eager-load across. Both escape
 * `BrandScope` and name the brand by hand: the programme editor runs on the
 * operator admin host, where the scope hardens to zero rows (ADR-0029), and
 * the public page is read with the brand the event belongs to.
 */
final class SelectedCompetitions
{
    /**
     * In the organizer's order.
     *
     * @return list<array{entry: CompetitionEntry, competition: ProgrammeCompetition}>
     */
    public static function forEvent(string $eventId, string $brandId): array
    {
        $entries = CompetitionEntry::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $competitions = ProgrammeCompetition::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->whereIn('competition_id', $entries->pluck('competition_id')->all())
            ->get()
            ->keyBy('competition_id');

        $selected = [];

        foreach ($entries as $entry) {
            $competition = $competitions->get($entry->competition_id);

            if ($competition instanceof ProgrammeCompetition) {
                $selected[] = ['entry' => $entry, 'competition' => $competition];
            }
        }

        return $selected;
    }
}
