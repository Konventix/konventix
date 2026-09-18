<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Admission knows it (ADR-0004: this module may not read
 * Event's models, so it projects what it needs from Event's contract
 * events) — design doc §13, 2026-09-11 amendment "Admission learns event
 * facts".
 *
 * Exists for the ticket's event band (§4.2) and, through
 * `Konventix\Platform\Calendar\CalendarFile`, the calendar file's
 * `LOCATION`: a name, a local start and end plus the timezone they are
 * promised in (ADR-0027 — stored wall-clock, exactly as Event's own
 * `starts_at`/`ends_at`), and the primary venue's name and city.
 *
 * Sits beside `EntranceEventProfile`, which holds only the day calendar and
 * the re-entry policy the door needs offline (ADR-0033) — a different
 * concern, not extended for this.
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated`/`EventRescheduled`/`VenueAssigned` rather than
 * inventing an event.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $name
 * @property string|null $slug
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $timezone
 * @property string|null $venue_label
 * @property string|null $venue_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'admission_event_summaries';

    protected $fillable = ['event_id', 'name', 'slug', 'starts_at', 'ends_at', 'timezone', 'venue_label', 'venue_id', 'banner_media_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
