<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Competition knows it (design §10, ADR-0004): the two facts the
 * public tournament pages need and Competition may not read from Event's
 * models — the `slug` `/events/{slug}/tournaments` resolves by, and the
 * `name` the pages are headed with.
 *
 * And the event's lifecycle `state`, because a draft event is nobody's
 * business: Event's own landing page 404s it, and so do these pages
 * (`isPublic()`).
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated` rather than inventing one. An event that
 * predates the projection arrives through `event:replay-details
 * --with-state`, which re-emits `EventDetailsUpdated` and the event's
 * current lifecycle event for every event.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $state
 * @property string|null $timezone the event's IANA zone (ADR-0027), for showing match times on its wall clock
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'competition_event_summaries';

    protected $fillable = ['event_id', 'slug', 'name', 'state', 'timezone'];

    /**
     * The lifecycle states in which an event's tournaments are public —
     * Agenda's set for the public programme (`Agenda\Models\EventSummary`),
     * spelled as `EventLifecycleState`'s backing values because Competition
     * may import only Event's events (ADR-0004).
     *
     * `draft` and `announced` are the organizer's workbench, `cancelled` and
     * `archived` are over and the event's own landing page says so;
     * `concluded` stays up, because a bracket is also a record of what
     * happened.
     */
    private const PUBLIC_STATES = ['published', 'running', 'concluded'];

    /**
     * Whether `/events/{slug}/tournaments` and the pages under it resolve.
     * A null state is "nobody has told us yet", and the answer is no — the
     * same safe default Agenda takes; the backfill closes it.
     */
    public function isPublic(): bool
    {
        return in_array($this->state, self::PUBLIC_STATES, true);
    }

    /**
     * The same question for a participant write, which is keyed by a
     * competition's or an entrant's id and never passes through
     * `/events/{slug}`: a competition of a draft event must not take an
     * entry, a report or an interest by id either (final review, part 4,
     * M10; fix wave B, item 3). The page's rule exactly — a projected name
     * and a public state — through the ambient `BrandScope`, so another
     * brand's event is as absent as a draft.
     */
    public static function isPublicEvent(string $eventId): bool
    {
        /** @var self|null $event */
        $event = self::query()->where('event_id', $eventId)->first();

        return $event !== null && $event->name !== null && $event->isPublic();
    }
}
