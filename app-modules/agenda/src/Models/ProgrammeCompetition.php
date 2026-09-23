<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Konventix\Agenda\Enums\CompetitionBlockState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A publicly announced competition as Agenda knows it (timetable design
 * §4.5): the name and slug its block shows and links with, the start time
 * Competition owns (D-AGD-03 — Agenda never writes it), and whether the
 * block stands or is struck.
 *
 * No factory: rows exist only through `Listeners\ProjectProgrammeCompetition`
 * — the `EventSummary` precedent — so a test emits `CompetitionPublished`
 * (`agendaPublishedCompetition()`) rather than inventing one.
 *
 * Whether a competition *appears* in the programme is not this row's
 * question; that is the organizer's selection, `CompetitionEntry`
 * (D-AGD-02).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $competition_id
 * @property string $name
 * @property string|null $slug
 * @property CarbonImmutable|null $starts_at
 * @property CompetitionBlockState $state
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProgrammeCompetition extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'agenda_competitions';

    protected $fillable = ['event_id', 'competition_id', 'name', 'slug', 'starts_at', 'state'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'state' => CompetitionBlockState::class,
        ];
    }
}
