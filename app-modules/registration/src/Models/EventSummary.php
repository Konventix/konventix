<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

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

    protected $fillable = ['event_id', 'name', 'slug', 'visibility', 'public_since', 'archived_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'public_since' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
