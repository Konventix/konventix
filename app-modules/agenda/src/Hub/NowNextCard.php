<?php

declare(strict_types=1);

namespace Konventix\Agenda\Hub;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Konventix\Agenda\Data\NowNextCardData;
use Konventix\Agenda\Models\EventSummary;
use Konventix\Agenda\Programme\NowNext;
use Konventix\Platform\Data\ActiveEventData;

/**
 * The payload behind the hub's `agenda.now-next` card (design §6): what is
 * running at this moment, what starts next, and the way to the whole
 * programme.
 *
 * An invokable builder rather than an Action, the shape
 * `Konventix\Admission\Hub\MyTicketCard` established: nothing here is a
 * command — it answers a question for one render, and the answer's shape is
 * what `HubCard`'s payload closure must return. The closure in
 * `AgendaServiceProvider::registerHub()` resolves this class per request,
 * so nothing is captured by the closure the singleton registry holds for
 * the life of an Octane worker (`.ai/rules/providers.md`).
 *
 * `$user` is unused and stays in the signature: `HubCard`'s payload
 * contract takes the viewer, and a programme is the same for every one of
 * them — it is public, and what is on at 14:00 does not depend on who is
 * asking.
 *
 * No `withoutBrandScope()`: the hub renders on the brand host inside a
 * request, so the ordinary scope is exactly the right filter (ADR-0010).
 * The brand the card then reports on is the summary row's own, which is
 * what `NowNext` needs explicitly.
 *
 * The clock is the **event's**, not the viewer's (ADR-0027): a participant
 * reading this card is standing in the hall, and "jetzt" means the hall's
 * clock.
 */
final class NowNextCard
{
    public function __construct(private readonly NowNext $nowNext) {}

    public function __invoke(ActiveEventData $event, Authenticatable $user): NowNextCardData
    {
        $summary = EventSummary::query()->where('event_id', $event->eventId)->first();

        // An event this module was never told about has no programme. The
        // card says "nothing is running" rather than failing the hub —
        // `HubPage` would contain a throwing card, but an empty one is the
        // honest answer here, not an incident.
        if ($summary === null) {
            return new NowNextCardData(
                now: null,
                next: null,
                programmeUrl: $this->programmeUrl($event->eventSlug),
            );
        }

        $timezone = $summary->eventTimezone();

        [
            'now' => $now,
            'next' => $next,
        ] = $this->nowNext->forEvent(
            eventId: $summary->event_id,
            brandId: $summary->brand_id,
            at: CarbonImmutable::now($timezone),
        );

        return new NowNextCardData(
            now: $now,
            next: $next,
            programmeUrl: $this->programmeUrl($summary->slug ?? $event->eventSlug),
        );
    }

    /**
     * Relative: the hub renders under the brand's own host and Inertia
     * follows it as a client-side visit — `MyTicketCard`'s idiom.
     *
     * An event with no slug has no public page to link to, and the empty
     * string is what the component hides the link on. That is a gap, not a
     * guess: a wrong URL on a card people follow in a hall is worse than
     * no URL.
     */
    private function programmeUrl(?string $slug): string
    {
        return $slug === null || $slug === ''
            ? ''
            : route('agenda.programme', ['slug' => $slug], absolute: false);
    }
}
