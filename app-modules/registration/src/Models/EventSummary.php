<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Registration knows it (ADR-0004: this module may not read
 * Event's models, so it projects what it needs from Event's contract events).
 *
 * Exists so an organizer creating a registration type picks an event by name
 * instead of pasting a ULID. Name, slug, whether it is archived, and — since
 * #240 — since when it is publicly visible, because `/shop` lists a brand's
 * registration types across every event and must not advertise a draft one.
 *
 * `public_since` is the projection of `Event::isVisibleOnLanding()`'s rule
 * ("has left `Draft`"): set by `EventAnnounced` and `EventPublished`, and
 * never cleared — `UnpublishEvent` returns the event to `Announced`, where
 * its landing page still sells. Null therefore means "still a draft", which
 * is exactly what a summary projected by `EventCreated` alone says.
 * `archived_at` is the column for an event that leaves public view for
 * good.
 *
 * `visibility` is the other half, and the two together are
 * `Event::scopeListedPublicly()` rather than `isVisibleOnLanding()` (branch
 * review blocker 2): a *listing* surface owes the stricter rule, because
 * `Unlisted` means reachable by direct link and never listed. `/shop` asks
 * for both; the landing page asks for neither, because it is addressed by
 * the event itself and Event has already decided.
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated` rather than inventing an event.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property string $slug
 * @property string|null $visibility
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $timezone
 * @property string|null $venue_label
 * @property string|null $banner_media_id
 * @property string|null $venue_id
 * @property Carbon|null $public_since
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'registration_event_summaries';

    /**
     * The one visibility a listing surface may show. Compared as a string,
     * never as `Konventix\Event\Enums\EventVisibility`: that enum is
     * Event's, and this module may not import it (ADR-0004). The value
     * arrives on the contract event as a scalar for the same reason.
     */
    public const string VISIBILITY_PUBLIC = 'public';

    protected $fillable = ['event_id', 'name', 'slug', 'visibility', 'starts_at', 'ends_at', 'timezone', 'venue_label', 'venue_id', 'banner_media_id', 'public_since', 'archived_at'];

    /**
     * This module's projection of `Event::scopeListedPublicly()` — the rule
     * a *listing* surface owes before it names an event it was not
     * addressed by: out of `Draft` (`public_since`), `Public` rather than
     * `Unlisted` (`visibility`), and not archived. Each of the three was a
     * real leak on `/shop` (#240): next year's unannounced convention, an
     * invite-only track, last year's edition. The `shop.index` slot and the
     * `tickets-for-event` block share it so the two cannot drift.
     *
     * A row that has never been told its visibility stays null, which
     * reads as "not listable" — the safe direction.
     *
     * @param  Builder<EventSummary>  $query
     * @return Builder<EventSummary>
     */
    public function scopeListedPublicly(Builder $query): Builder
    {
        return $query
            ->whereNotNull('public_since')
            ->where('visibility', self::VISIBILITY_PUBLIC)
            ->whereNull('archived_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'public_since' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
