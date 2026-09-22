<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Community\Models\EventPresence;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;

/**
 * Projects Admission's participant events into Community's
 * `community_event_presence` read model (design §4.5, ADR-0067 decision 2)
 * — Community may not read Admission's own `admission_event_presence`
 * table (ADR-0004). Registered explicitly for all three events in
 * `CommunityServiceProvider::registerListeners()` — discovery is off and
 * must stay off (.ai/rules/listeners.md).
 *
 * The `Konventix\Admission\Events\` imports are the sanctioned exception:
 * events are the public contract between modules (ADR-0004).
 *
 * This is a **badge, never a gate** (ADR-0067 decision 2): nothing here
 * grants, refuses or reads anything back for access control. The shoutbox's
 * own access gate is Platform's `ActiveEventContext`.
 *
 * `ParticipantAdmissionReplaced` is deliberately NOT listened to here. Its
 * own docblock says why it exists and what it does not mean: a survivor
 * admission takes over an existing presence row when another of a holder's
 * admissions for the same event is revoked, and "access is unchanged by
 * definition" is the whole point of the event — `ProjectEventPresence`
 * emits it *instead of* `ParticipantHubAccessEnded` for exactly that
 * reason. It exists for consumers that mirror `admission_id` (Platform's
 * `platform_hub_access`, Resources' seat link); this table has no such
 * column and the badge it carries — checked-in, last direction — is
 * unaffected by which admission happens to back it. Listening here would
 * mean writing a handler with nothing true to say.
 */
final class ProjectAdmissionBadge
{
    public function handle(ParticipantAdmitted|ParticipantPresenceChanged|ParticipantHubAccessEnded $event): void
    {
        match (true) {
            $event instanceof ParticipantAdmitted => $this->admitted($event),
            $event instanceof ParticipantPresenceChanged => $this->presenceChanged($event),
            default => $this->accessEnded($event),
        };
    }

    /**
     * Sets the badge on. Only `checked_in` is touched — `last_direction`
     * travels on `ParticipantPresenceChanged`, not on this event, and is
     * left alone for a row that already has one.
     */
    private function admitted(ParticipantAdmitted $event): void
    {
        if (! $this->moduleActive($event->brandId)) {
            return;
        }

        $presence = $this->rowFor($event->brandId, $event->eventId, $event->userId);

        // withoutBrandScope() because this listener runs off any host, with
        // no ambient brand in context. `brand_id` is NOT mass-assignable
        // (.ai/rules/models.md — BelongsToBrand's invariant is that only
        // the ambient-context `creating` hook or a named escape hatch sets
        // it), so this projection-only writer sets it directly via
        // forceFill() instead of putting it through fill()/mass assignment:
        // the value comes from the event, never from ambient context, and
        // must not become something an ordinary caller's array could
        // override.
        $presence->forceFill([
            'brand_id' => $event->brandId,
            'event_id' => $event->eventId,
            'user_id' => $event->userId,
            'checked_in' => true,
        ])->save();
    }

    /**
     * Flips `last_direction` to the scan the desk just recorded. Only that
     * column is touched — `checked_in` is untouched either way, `out`
     * included: stepping out is not losing the badge, only
     * `ParticipantHubAccessEnded` does that (design §4.5, §5).
     */
    private function presenceChanged(ParticipantPresenceChanged $event): void
    {
        if (! $this->moduleActive($event->brandId)) {
            return;
        }

        $presence = $this->rowFor($event->brandId, $event->eventId, $event->userId);

        $presence->forceFill([
            'brand_id' => $event->brandId,
            'event_id' => $event->eventId,
            'user_id' => $event->userId,
            'last_direction' => $event->direction,
        ])->save();
    }

    /**
     * Clears the badge outright. Hub access ending — the window closed, the
     * event concluded, the admission was revoked with no survivor — leaves
     * nothing worth projecting, the same "no half-life for expired state"
     * shape as `ProjectEventParticipation::removed()`. Deleting a row that
     * is not there is a no-op, which is what keeps a replay idempotent.
     */
    private function accessEnded(ParticipantHubAccessEnded $event): void
    {
        if (! $this->moduleActive($event->brandId)) {
            return;
        }

        EventPresence::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->where('user_id', $event->userId)
            ->delete();
    }

    private function moduleActive(string $brandId): bool
    {
        $brand = Brand::query()->find($brandId);

        return $brand !== null && Feature::for($brand)->active(app(ModuleGate::class)->featureName('community'));
    }

    /**
     * The one row a user has at an event (`unique(event_id, user_id)`),
     * read by the payload's own brand — never through the ambient scope.
     */
    private function rowFor(string $brandId, string $eventId, string $userId): EventPresence
    {
        return EventPresence::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->where('user_id', $userId)
            ->firstOrNew();
    }
}
