<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Konventix\Community\Models\CommunityEventParticipation;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Registration\Events\RegistrationSubmitted;
use Laravel\Pennant\Feature;

/**
 * Projects Registration's contract events into Community's
 * `community_event_participations` read model (ADR-0052 §3) — Community may
 * not read Registration's models (ADR-0004). Registered explicitly for all
 * four events in `CommunityServiceProvider::registerListeners()` — discovery
 * is off and must stay off (.ai/rules/listeners.md).
 *
 * The `Konventix\Registration\Events\` imports are the sanctioned exception:
 * events are the public contract between modules (ADR-0004).
 *
 * Participation is projected going forward only (ADR-0052 §4): Registration
 * has no replay command yet, so a brand that activates Community after
 * registrations have closed for an event starts with empty participation
 * for it.
 */
final class ProjectEventParticipation
{
    public function handle(RegistrationSubmitted|RegistrationConfirmed|RegistrationCancelled|RegistrationRejected $event): void
    {
        match (true) {
            $event instanceof RegistrationSubmitted => $this->submitted($event),
            $event instanceof RegistrationConfirmed => $this->confirmed($event),
            default => $this->removed($event->registrationId),
        };
    }

    /**
     * Creates the unconfirmed row. Gated on the EVENT's brand, not the
     * request's — replays (once Registration has one) run with no request.
     * A brand that turns Community on late is simply missing history
     * (ADR-0052 §4), not backfilled here.
     */
    private function submitted(RegistrationSubmitted $event): void
    {
        $brand = Brand::query()->find($event->brandId);

        if ($brand === null || ! Feature::for($brand)->active(app(ModuleGate::class)->featureName('community'))) {
            return;
        }

        // withoutBrandScope() because this listener runs off any host, with
        // no ambient brand in context. `brand_id` is NOT mass-assignable
        // (.ai/rules/models.md — BelongsToBrand's invariant is that only
        // the ambient-context `creating` hook or a named escape hatch sets
        // it), so this projection-only writer sets it directly via
        // forceFill() instead of putting it through fill()/mass assignment:
        // the value comes from the event, never from ambient context, and
        // must not become something an ordinary caller's array could
        // override.
        $participation = CommunityEventParticipation::withoutBrandScope()
            ->where('id', $event->registrationId)
            ->firstOrNew();

        $participation->forceFill([
            'id' => $event->registrationId,
            'brand_id' => $event->brandId,
            'user_id' => $event->registrantUserId,
            'event_id' => $event->eventId,
            'confirmed' => false,
        ])->save();
    }

    /**
     * Flips `confirmed` by registrationId. No user id travels on this event
     * (SPEC.md "Public contract") — the row created on submission is enough
     * to find. A missing row (module was off when submitted) is a silent
     * no-op, same as any other projection racing ahead of its own gate.
     */
    private function confirmed(RegistrationConfirmed $event): void
    {
        CommunityEventParticipation::withoutBrandScope()
            ->where('id', $event->registrationId)
            ->update(['confirmed' => true]);
    }

    /**
     * Cancellation and rejection both remove the row outright — there is no
     * "used to be confirmed" state worth keeping for a visibility check
     * (ADR-0052 §3).
     */
    private function removed(string $registrationId): void
    {
        CommunityEventParticipation::withoutBrandScope()
            ->where('id', $registrationId)
            ->delete();
    }
}
