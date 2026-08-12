<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Event\Database\Factories\EventFactory;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The concrete occurrence a brand puts on (SPEC.md "Owns" — Event): identity,
 * dates, lifecycle state, its days, and the venues it happens at
 * (many, with one primary — D-EVT-15). Almost everything else
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
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property EventLifecycleState $state
 * @property EventVisibility $visibility
 * @property ReentryPolicy $reentry_policy
 * @property string|null $predecessor_event_id
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
        'name',
        'slug',
        'description',
        'starts_at',
        'ends_at',
        'timezone',
        'state',
        'visibility',
        'reentry_policy',
        'predecessor_event_id',
        'published_at',
        'cancelled_at',
        'concluded_at',
        'archived_at',
        'reason_code',
    ];

    /**
     * The venues this event happens at (D-EVT-15: many, with one primary;
     * zero venues = online-only). Managed through `Actions\AssignVenue` /
     * `Actions\UnassignVenue`, never raw attach — assignment emits the
     * contract event consumers rely on.
     *
     * @return BelongsToMany<Venue, $this, EventVenue>
     */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'event_venues')
            ->using(EventVenue::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function primaryVenue(): ?Venue
    {
        return $this->venues->firstWhere(fn (Venue $venue): bool => (bool) $venue->pivot?->getAttribute('is_primary'));
    }

    /**
     * @return HasMany<EventDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(EventDay::class)->orderBy('sequence');
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
            'reentry_policy' => ReentryPolicy::class,
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
