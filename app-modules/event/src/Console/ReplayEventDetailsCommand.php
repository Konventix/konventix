<?php

declare(strict_types=1);

namespace Konventix\Event\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\VenueAssigned;
use Konventix\Event\Models\Event;

/**
 * Re-emits `EventRescheduled`, `EventDetailsUpdated` and, for an event with
 * a primary venue, `VenueAssigned` — for every event (or one), carrying the
 * row's current values. Read models in other modules project from these
 * events and therefore only know events that changed after the projection
 * existed; Resources' `resource_event_windows` was the first that needed a
 * backfill when a brand switches the module on mid-season, and Admission's
 * `admission_event_summaries` (design doc §13) is the first that needs the
 * venue too. A replay rebuilds them from the contract, which is what
 * ADR-0004 promises — the same shape as `identity:replay-profiles`.
 *
 * Order matters and is deliberate: the schedule first, because it is the
 * event that can *create* a projection's row (it carries the times and,
 * since S0, the brand); the details second, because they only *name* a row
 * that already exists; the venue third, because without a row already
 * standing there is nothing to attach a `LOCATION` to. Idempotent by
 * construction: every consumer upserts.
 *
 * `VenueAssigned` is re-fired only for the event's *primary* venue
 * (`Event::primaryVenue()`) — a secondary venue is never re-emitted here,
 * matching `Konventix\Event\Actions\AssignVenue`'s own contract, where only
 * `isPrimary: true` matters to a ticket's event band or a calendar
 * `LOCATION` (design §4.2, §6.1). An event with no venue emits none; a
 * backfilled summary for it therefore has no venue, which every consumer
 * must already tolerate (design §11).
 *
 * `--with-state` adds one more: the single lifecycle event that says what
 * state the event is in *now* (`lifecycleEventFor()`). Agenda's
 * `agenda_event_summaries` is the first read model whose whole public
 * surface hangs off that state — an event backfilled without it keeps a
 * `null` state and its programme has no public page at all until the next
 * transition, which for a published event may be never. Off by default,
 * because the three events above name and schedule a row while a lifecycle
 * event is a transition, and re-announcing one is only safe as long as
 * every listener on the seven is idempotent. That was verified module by
 * module when the option was added (see `docs/operations.md`); anyone
 * registering a *new* listener on one of them owes the same check.
 *
 * Deliberately not `EventCreated`: several modules react to creation with
 * side effects a replay must not repeat.
 */
final class ReplayEventDetailsCommand extends Command
{
    protected $signature = 'event:replay-details
        {--event= : Replay one event by ULID instead of every event}
        {--with-state : Also re-emit the current lifecycle event of each event, for read models that hold the lifecycle state}
        {--chunk=200 : Events per database round trip}';

    protected $description = 'Re-emit EventRescheduled, EventDetailsUpdated and the primary VenueAssigned for existing events so downstream read models can be rebuilt';

    public function handle(): int
    {
        $only = $this->option('event');
        $only = is_string($only) && $only !== '' ? $only : null;
        $withState = (bool) $this->option('with-state');
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Event::withoutBrandScope()->with('venues');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $replayed = 0;
        $states = 0;

        $query->chunkById($chunk, function (Collection $events) use (&$replayed, &$states, $withState): void {
            /** @var Event $event */
            foreach ($events as $event) {
                event(new EventRescheduled(
                    eventId: $event->id,
                    startsAt: $event->starts_at->format('Y-m-d H:i:s'),
                    endsAt: $event->ends_at->format('Y-m-d H:i:s'),
                    timezone: $event->timezone,
                    brandId: $event->brand_id,
                ));

                event(new EventDetailsUpdated(
                    eventId: $event->id,
                    brandId: $event->brand_id,
                    slug: $event->slug,
                    name: $event->name,
                    visibility: $event->visibility->value,
                    bannerMediaId: $event->banner_media_id,
                ));

                $venue = $event->primaryVenue();

                if ($venue !== null) {
                    event(new VenueAssigned(
                        eventId: $event->id,
                        venueId: $venue->id,
                        brandId: $event->brand_id,
                        name: $venue->name,
                        city: $venue->city,
                        isPrimary: true,
                    ));
                }

                if ($withState) {
                    $lifecycle = self::lifecycleEventFor($event);

                    if ($lifecycle !== null) {
                        event($lifecycle);
                        $states++;
                    }
                }

                $replayed++;
            }
        });

        if ($only !== null && $replayed === 0) {
            $this->components->error("No event [{$only}].");

            return self::FAILURE;
        }

        $this->components->info("Replayed {$replayed} event(s).");

        if ($withState) {
            $this->components->info("Re-emitted the lifecycle state of {$states} event(s); a draft has none to re-emit.");
        }

        return self::SUCCESS;
    }

    /**
     * The one lifecycle event that says what state this event is in *now*,
     * or null for a draft, which has no lifecycle event at all — a
     * projection that never heard one is already indistinguishable from one
     * that heard "draft", and a draft's public surfaces are closed either
     * way.
     *
     * Only the current state, never the path that led to it: replaying
     * `EventConcluded` before `EventArchived` for an archived event would
     * restart Compliance's retention clock from today, and nothing that
     * holds the state needs the history to arrive at the right answer.
     * That is also why `EventUnpublished` never appears here — unpublishing
     * lands on `Announced`, and `EventAnnounced` is what says so.
     *
     * Two of the seven states keep no timestamp of their own (`Announced`
     * and `Running` have no column — `AnnounceEvent` and `StartEvent` pass
     * `now()`), so those fall back to the row's own `updated_at`. That is
     * approximate on purpose and costs nothing: the only consumers of those
     * two payloads' timestamps ignore them or take the first value they
     * ever saw (`Registration\Listeners\ProjectEventSummary::markPublic()`
     * writes only `whereNull('public_since')`). The four that *are* stored
     * — published, cancelled, concluded, archived — are replayed exactly,
     * so Compliance's clock and Admission's access-ended stamps land on the
     * same instants they did the first time.
     */
    private static function lifecycleEventFor(Event $event): EventAnnounced|EventPublished|EventStarted|EventConcluded|EventCancelled|EventArchived|null
    {
        $at = static fn (?Carbon $stored): string => ($stored ?? $event->updated_at ?? $event->created_at ?? now())->toIso8601String();

        return match ($event->state) {
            EventLifecycleState::Draft => null,
            EventLifecycleState::Announced => new EventAnnounced($event->id, $event->brand_id, $at(null)),
            EventLifecycleState::Published => new EventPublished($event->id, $event->brand_id, $at($event->published_at)),
            EventLifecycleState::Running => new EventStarted($event->id, $event->brand_id, $at(null)),
            EventLifecycleState::Concluded => new EventConcluded($event->id, $event->brand_id, $at($event->concluded_at)),
            EventLifecycleState::Cancelled => new EventCancelled($event->id, $event->brand_id, $event->reason_code ?? '', $at($event->cancelled_at)),
            EventLifecycleState::Archived => new EventArchived($event->id, $event->brand_id, $at($event->archived_at)),
        };
    }
}
