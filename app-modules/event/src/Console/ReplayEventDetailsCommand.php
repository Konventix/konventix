<?php

declare(strict_types=1);

namespace Konventix\Event\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
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
 * Deliberately not `EventCreated`: several modules react to creation with
 * side effects a replay must not repeat.
 */
final class ReplayEventDetailsCommand extends Command
{
    protected $signature = 'event:replay-details
        {--event= : Replay one event by ULID instead of every event}
        {--chunk=200 : Events per database round trip}';

    protected $description = 'Re-emit EventRescheduled, EventDetailsUpdated and the primary VenueAssigned for existing events so downstream read models can be rebuilt';

    public function handle(): int
    {
        $only = $this->option('event');
        $only = is_string($only) && $only !== '' ? $only : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Event::withoutBrandScope()->with('venues');

        if ($only !== null) {
            $query->whereKey($only);
        }

        $replayed = 0;

        $query->chunkById($chunk, function (Collection $events) use (&$replayed): void {
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

                $replayed++;
            }
        });

        if ($only !== null && $replayed === 0) {
            $this->components->error("No event [{$only}].");

            return self::FAILURE;
        }

        $this->components->info("Replayed {$replayed} event(s).");

        return self::SUCCESS;
    }
}
