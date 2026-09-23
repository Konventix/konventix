<?php

declare(strict_types=1);

namespace Konventix\Agenda\Programme;

use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\ProgrammeCompetition;
use Konventix\Platform\Modules\ModuleGate;

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

    /**
     * The selection as the programme places it (§4.5): every selected
     * competition that has a start, as a `ProgrammeEntry`. A competition
     * with no `starts_at` has no anchor, so it has no day and no row — it
     * is left out, not put in the untracked band, which is per day too.
     *
     * Cancelled blocks are kept: the grid strikes them (D-AGD-08), and
     * `NowNext` skips them itself.
     *
     * The link goes to Competition's public page, named by its route only
     * (`competition.page`; Agenda imports nothing of Competition's), and
     * only when there is an event slug, a competition slug and Competition
     * is on for the brand. A link that 404s on a card people follow in a
     * hall is worse than none — `NowNextCard::programmeUrl()`'s reasoning.
     * The gate is asked once per call, and only when something is selected.
     *
     * @return list<ProgrammeEntry>
     */
    public static function programmeEntries(string $eventId, string $brandId, ?string $eventSlug): array
    {
        $scheduled = array_values(array_filter(
            self::forEvent($eventId, $brandId),
            static fn (array $pair): bool => $pair['competition']->starts_at !== null,
        ));

        if ($scheduled === []) {
            return [];
        }

        $linkable = $eventSlug !== null && $eventSlug !== ''
            && app(ModuleGate::class)->isActiveForBrand('competition', $brandId);

        $entries = [];

        foreach ($scheduled as ['entry' => $entry, 'competition' => $competition]) {
            $slug = $competition->slug;

            $url = $linkable && $slug !== null && $slug !== ''
                ? route('competition.page', ['slug' => $eventSlug, 'competition' => $slug], absolute: false)
                : null;

            $entries[] = ProgrammeEntry::ofCompetition($competition, $entry, $url);
        }

        return $entries;
    }
}
