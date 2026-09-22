<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Event\Database\Factories\EventDayFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One day of an event, first-class (decision log Round 6, D-ADM-04 — user
 * override): Admission validates an admission against a specific day
 * rather than only a from/until pair, so days are entities with stable
 * ULIDs other modules reference (ADR-0014), not derived date math.
 *
 * `sequence` is 1-based display/order ("Day 2"); `date` is the wall-clock
 * calendar date in the event's own timezone (ADR-0027 — a date, not an
 * instant). Managed exclusively through `Actions\SyncEventDays`, which
 * emits `EventDaysChanged` so Admission's validity evaluation can react.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property Carbon $date
 * @property int $sequence
 * @property string|null $label
 * @property string|null $day_ends_at `HH:MM`; null means 00:00 of the following day (D-HUB-01)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventDay extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EventDayFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['event_id', 'date', 'sequence', 'label', 'day_ends_at'];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sequence' => 'integer',
        ];
    }

    protected static function newFactory(): EventDayFactory
    {
        return EventDayFactory::new();
    }
}
