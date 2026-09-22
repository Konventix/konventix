<?php

declare(strict_types=1);

namespace Konventix\Platform\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Admission\Events\ParticipantAdmissionReplaced;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Models\HubAccess;

/**
 * Keeps `platform_hub_access` in step with Admission's four participant
 * events (design §5.1, ADR-0004) — Platform's only knowledge of who is
 * admitted to what. Registered in
 * `PlatformServiceProvider::registerListeners()`.
 *
 * Every handler is idempotent so a redelivered event changes nothing: the
 * row is keyed by `(event_id, user_id)`, `handleAdmitted()` upserts by
 * that key, and the other three tolerate a row that is already flipped,
 * already carrying the new value, or already gone. Each ends by forgetting
 * the member's cache entry, so the next request sees the change rather than
 * the TTL.
 *
 * The events arrive from queue workers with no ambient brand, so every
 * query escapes the brand scope by the payload's own brand id (ADR-0010),
 * and `brand_id` on a new row is set through `forceFill()` — it is never
 * fillable.
 */
final class ProjectHubAccess
{
    public function handleAdmitted(ParticipantAdmitted $event): void
    {
        $row = $this->rowFor($event->brandId, $event->eventId, $event->userId)
            ?? (new HubAccess)->forceFill(['brand_id' => $event->brandId]);

        // Anchor to UTC before assigning: the datetime cast writes a
        // Carbon's wall-clock digits without converting the zone, so an
        // instant with a +02:00 offset would land two hours late.
        $row->fill([
            'event_id' => $event->eventId,
            'user_id' => $event->userId,
            'admission_id' => $event->admissionId,
            'event_slug' => $event->eventSlug,
            'event_name' => $event->eventName,
            'day_label' => $event->dayLabel,
            'admitted_at' => CarbonImmutable::parse($event->admittedAt)->utc(),
            'window_ends_at' => $event->windowEndsAt === null ? null : CarbonImmutable::parse($event->windowEndsAt)->utc(),
            // A false→true admission is an accepted `in` scan (D-HUB-01).
            'on_site' => true,
        ])->save();

        ActiveEventContext::forget($event->brandId, $event->userId);
    }

    /**
     * "On site" is a badge, never a gate (D-HUB-02): the row stays, only
     * the flag moves. A presence change for someone this table never
     * admitted is a no-op — the admission event carries the row into being.
     */
    public function handlePresenceChanged(ParticipantPresenceChanged $event): void
    {
        $this->rowFor($event->brandId, $event->eventId, $event->userId)
            ?->fill(['on_site' => $event->direction === 'in'])
            ->save();

        ActiveEventContext::forget($event->brandId, $event->userId);
    }

    /**
     * Access did not change; the admission behind it did (D-HUB-01's
     * survivor rule). This row *mirrors* `admission_id`, and the hub's
     * "Mein Sitzplatz" link is built out of it, so a mirror left pointing
     * at the revoked admission would send the participant to a ticket that
     * no longer exists. A replacement for a row this table never admitted
     * is a no-op, like a presence change.
     */
    public function handleAdmissionReplaced(ParticipantAdmissionReplaced $event): void
    {
        $this->rowFor($event->brandId, $event->eventId, $event->userId)
            ?->fill(['admission_id' => $event->admissionId])
            ->save();

        ActiveEventContext::forget($event->brandId, $event->userId);
    }

    /**
     * Whatever the reason — day ended, event concluded or cancelled,
     * admission revoked — the hub is closed to them, and a row without
     * access says nothing this table is for. The next day's accepted scan
     * re-admits through `handleAdmitted()`.
     */
    public function handleEnded(ParticipantHubAccessEnded $event): void
    {
        HubAccess::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->where('user_id', $event->userId)
            ->delete();

        ActiveEventContext::forget($event->brandId, $event->userId);
    }

    private function rowFor(string $brandId, string $eventId, string $userId): ?HubAccess
    {
        return HubAccess::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->first();
    }
}
