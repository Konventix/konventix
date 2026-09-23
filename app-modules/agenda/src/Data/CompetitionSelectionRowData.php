<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Konventix\Agenda\Enums\CompetitionBlockState;
use Konventix\Agenda\Models\CompetitionEntry;
use Konventix\Agenda\Models\ProgrammeCompetition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of the programme editor's competition list (timetable design
 * §4.5, §6): a competition the editor may select, or one it already has.
 *
 * The list is the union of two sets that usually coincide:
 *
 * - every competition Agenda's read model knows for the event — publicly
 *   announced, with or without a start, standing or cancelled
 *   (`withdrawn = false`), selected when a `CompetitionEntry` names it;
 * - every `CompetitionEntry` whose read-model row is gone, because
 *   Competition unpublished the tournament after the organizer picked it
 *   (`withdrawn = true`). Agenda no longer knows its name — the row that
 *   held it was deleted — so the editor shows a placeholder and offers to
 *   remove the entry. The entry itself is kept until the organizer does, so
 *   that republishing restores the block where it was (Task 3, decision 2).
 *
 * `startsAt` is the instant Competition owns (D-AGD-03), ISO-8601 with its
 * offset, or null for a competition without a time yet.
 */
#[TypeScript]
final class CompetitionSelectionRowData extends Data
{
    public function __construct(
        public readonly string $competitionId,
        public readonly string $name,
        public readonly ?string $startsAt,
        public readonly bool $cancelled,
        public readonly bool $withdrawn,
        public readonly bool $selected,
        public readonly ?string $trackId,
        public readonly int $position,
    ) {}

    /**
     * The editor's list for one event: the selection first, in the
     * organizer's order, then the unselected competitions by start (those
     * without one last) and name.
     *
     * Two queries, both escaping `BrandScope` and naming the brand by hand:
     * the editor runs on the operator admin host, where the scope hardens
     * to zero rows (ADR-0029).
     *
     * Named `of*` rather than `from*` on purpose — laravel-data treats any
     * public static `from*` as a magic creator (`.ai/rules/data.md`).
     *
     * @return list<self>
     */
    public static function ofEvent(string $eventId, string $brandId): array
    {
        $entries = CompetitionEntry::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->keyBy('competition_id');

        $competitions = ProgrammeCompetition::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->orderByRaw('starts_at asc nulls last')
            ->orderBy('name')
            ->get()
            ->keyBy('competition_id');

        $selected = [];

        foreach ($entries as $competitionId => $entry) {
            $competition = $competitions->get($competitionId);

            $selected[] = $competition instanceof ProgrammeCompetition
                ? self::ofCompetition($competition, $entry)
                : new self(
                    competitionId: $entry->competition_id,
                    name: __('Withdrawn competition'),
                    startsAt: null,
                    cancelled: false,
                    withdrawn: true,
                    selected: true,
                    trackId: $entry->track_id,
                    position: $entry->position,
                );
        }

        $unselected = [];

        foreach ($competitions as $competitionId => $competition) {
            if (! $entries->has($competitionId)) {
                $unselected[] = self::ofCompetition($competition, null);
            }
        }

        return [...$selected, ...$unselected];
    }

    private static function ofCompetition(ProgrammeCompetition $competition, ?CompetitionEntry $entry): self
    {
        return new self(
            competitionId: $competition->competition_id,
            name: $competition->name,
            startsAt: $competition->starts_at?->utc()->toIso8601String(),
            cancelled: $competition->state === CompetitionBlockState::Cancelled,
            withdrawn: false,
            selected: $entry instanceof CompetitionEntry,
            trackId: $entry?->track_id,
            position: $entry->position ?? 0,
        );
    }
}
