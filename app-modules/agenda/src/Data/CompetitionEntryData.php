<?php

declare(strict_types=1);

namespace Konventix\Agenda\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One competition the programme editor chose to show, as the editor posts
 * it (timetable design §4.5, D-AGD-02): the single source of validation and
 * generated TypeScript for a selection entry (ADR-0009).
 *
 * The editor posts the whole selection at once and `SaveCompetitionSelection`
 * replaces the stored set with it, so there is no `id` here: an entry is
 * identified by its competition, which `agenda_competition_entries` holds
 * unique per event.
 *
 * The controller lifts these rules onto `competitions.*.`, so `distinct`
 * compares the posted set against itself and fails on the offending row's
 * own indexed key. Whether the ids name *this event's* competitions and
 * tracks is not a question these rules can answer — that needs the event,
 * which the Action knows and the Data does not.
 */
#[TypeScript]
final class CompetitionEntryData extends Data
{
    public function __construct(
        public readonly string $competitionId,
        public readonly ?string $trackId,
        public readonly int $position,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'competitionId' => ['required', 'ulid', 'distinct'],
            'trackId' => ['nullable', 'ulid'],
            'position' => ['required', 'integer', 'min:0'],
        ];
    }
}
