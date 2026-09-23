<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Agenda\Database\Factories\CompetitionEntryFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One competition the programme editor chose to show (timetable design
 * §4.5, D-AGD-02): the selection belongs to the person composing the
 * programme, and Competition does not know it is being displayed.
 *
 * `competition_id` is a plain ULID with no FK anywhere — not even to
 * `ProgrammeCompetition`, whose row is deleted when the competition is
 * unpublished while this selection survives, so a republished competition
 * returns to the track and position the organizer gave it. `track_id` is a
 * real in-module FK, `nullOnDelete`.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $competition_id
 * @property string|null $track_id
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Track|null $track
 */
class CompetitionEntry extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CompetitionEntryFactory> */
    use HasFactory, HasUlids;

    protected $table = 'agenda_competition_entries';

    protected $fillable = ['event_id', 'competition_id', 'track_id', 'position'];

    /**
     * @return BelongsTo<Track, $this>
     */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): CompetitionEntryFactory
    {
        return CompetitionEntryFactory::new();
    }
}
