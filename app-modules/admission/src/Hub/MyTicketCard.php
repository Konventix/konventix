<?php

declare(strict_types=1);

namespace Konventix\Admission\Hub;

use Illuminate\Contracts\Auth\Authenticatable;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Data\MyTicketCardData;
use Konventix\Admission\Documents\TicketQr;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventSummary;
use Konventix\Platform\Data\ActiveEventData;

/**
 * The payload behind the hub's `admission.my-ticket` card (D-HUB-05,
 * design §5.2): the scan code, who it admits as what, and the one sentence
 * that decides whether the participant may walk back in later.
 *
 * An invokable builder rather than a Laravel Action: nothing here is a
 * command — it answers a question for one render, and the answer's shape
 * is what `HubCard`'s payload closure must return. The closure in
 * `AdmissionServiceProvider::registerHub()` resolves this class per
 * request, so neither dependency is captured by a closure the singleton
 * registry holds for the life of an Octane worker
 * (`.ai/rules/providers.md`).
 *
 * `current()`, never `rotate()`: opening the hub must not invalidate a
 * code already on screen or on paper (`.ai/rules/tickets.md`).
 *
 * No `withoutBrandScope()` anywhere here, unlike `ActiveEventContext`: the
 * hub renders on the brand host inside a request, so a brand is resolved
 * and the normal scope is exactly the right filter (ADR-0010) — an
 * admission belonging to another brand must not resolve at all.
 *
 * `$user` is the second half of the lookup, not decoration. What this card
 * renders is a **signed entrance credential**, and `ActiveEventData`
 * carries an admission id out of a projection two modules away — a row
 * that is wrong, stale or rebuilt from a bad replay would have this card
 * printing somebody else's QR under this viewer's name. So the admission
 * has to be the viewer's own as well as the brand's: no holder match, no
 * row, and `HubPage`'s guard drops the card (D-HUB-05). A gap is the right
 * failure here; the wrong ticket is not.
 */
final class MyTicketCard
{
    public function __construct(
        private readonly IssueAdmissionToken $tokens,
        private readonly TicketQr $qr,
    ) {}

    public function __invoke(ActiveEventData $event, Authenticatable $user): MyTicketCardData
    {
        $admission = Admission::query()
            ->whereKey($event->admissionId)
            ->where('holder_user_id', $user->getAuthIdentifier())
            ->firstOrFail();
        $summary = EventSummary::query()->where('event_id', $event->eventId)->first();
        $profile = EntranceEventProfile::query()->where('event_id', $event->eventId)->first();

        return new MyTicketCardData(
            admissionId: $admission->id,
            eventName: $summary?->name,
            role: str_replace('_', ' ', $admission->role),
            qrSvg: $this->qr->svg($this->tokens->current($admission)),
            // Relative: the hub is rendered under the brand's own host and
            // Inertia follows it as a client-side visit.
            ticketUrl: route('admission.tickets.show', $admission->id, absolute: false),
            reentryHint: $this->reentryHint($profile?->reentry_policy),
        );
    }

    /**
     * D-ADM-03 in the words of somebody standing at the door, in German
     * like the rest of the participant's surface. No profile means the
     * event never narrowed it — Admission's projection writes a row only
     * once Event says something — so the answer is the open one, which is
     * also `ReentryPolicy::Unlimited`'s.
     */
    private function reentryHint(?ReentryPolicy $policy): string
    {
        return match ($policy) {
            ReentryPolicy::OncePerDay => 'Einlass täglich neu – bitte den Code jeden Tag scannen lassen.',
            ReentryPolicy::OnceOnly => 'Einmaliger Einlass – der Code gilt nur beim ersten Scan.',
            default => 'Ein- und Ausgang jederzeit mit diesem Code.',
        };
    }
}
