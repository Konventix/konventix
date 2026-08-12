<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Event\Database\Factories\EventFactory;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The concrete occurrence a brand puts on (SPEC.md "Owns" — Event): identity,
 * dates, lifecycle state, and the venue it happens at. Almost everything else
 * hangs off this via a plain `event_id` ULID (ADR-0014) — Event imports no
 * other module's models (SPEC.md "Purpose").
 *
 * `starts_at`/`ends_at` are stored wall-clock, with `timezone` as the IANA
 * zone they are promised in (ADR-0027). They are *not* instants: read them
 * through `startsAtInstant()`/`endsAtInstant()` when you need a real,
 * comparable point in time. Never `Carbon::parse()` them inline elsewhere.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $venue_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property EventLifecycleState $state
 * @property EventVisibility $visibility
 * @property Carbon|null $published_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $concluded_at
 * @property Carbon|null $archived_at
 * @property string|null $reason_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Event extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'venue_id',
        'name',
        'slug',
        'description',
        'starts_at',
        'ends_at',
        'timezone',
        'state',
        'visibility',
        'published_at',
        'cancelled_at',
        'concluded_at',
        'archived_at',
        'reason_code',
    ];

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /**
     * The organizer's promised local start time, converted to a real instant
     * through this event's timezone (ADR-0027). The single accessor
     * consuming code must use instead of `Carbon::parse($event->starts_at)`.
     */
    public function startsAtInstant(): Carbon
    {
        return $this->wallClockToInstant('starts_at');
    }

    /**
     * The organizer's promised local end time, converted to a real instant
     * through this event's timezone (ADR-0027).
     */
    public function endsAtInstant(): Carbon
    {
        return $this->wallClockToInstant('ends_at');
    }

    private function wallClockToInstant(string $attribute): Carbon
    {
        /** @var Carbon $wallClock */
        $wallClock = $this->getAttribute($attribute);

        return Carbon::parse($wallClock->format('Y-m-d H:i:s'), $this->timezone);
    }

    /**
     * Whether this event's landing route (routes/web/event.php) should
     * resolve rather than 404. `Unlisted` events are reachable via direct
     * link at any lifecycle state; `Public` events are reachable once they
     * have left `Draft` (SPEC.md "Event visibility").
     */
    public function isVisibleOnLanding(): bool
    {
        if ($this->visibility === EventVisibility::Unlisted) {
            return true;
        }

        return $this->state !== EventLifecycleState::Draft;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'state' => EventLifecycleState::class,
            'visibility' => EventVisibility::class,
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'concluded_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
