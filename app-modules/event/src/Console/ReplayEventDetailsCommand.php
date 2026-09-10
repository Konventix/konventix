<?php

declare(strict_types=1);

namespace Konventix\Event\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Models\Event;

/**
 * Re-emits `EventRescheduled` and `EventDetailsUpdated` for every event (or
 * one), carrying the row's current values. Read models in other modules
 * project from these events and therefore only know events that changed
 * after the projection existed; Resources' `resource_event_windows` is the
 * first that needs a backfill when a brand switches the module on
 * mid-season. A replay rebuilds it from the contract, which is what
 * ADR-0004 promises — the same shape as `identity:replay-profiles`.
 *
 * Order matters and is deliberate: the schedule first, because it is the
 * event that can *create* a window (it carries the times and, since S0, the
 * brand); the details second, because they only *name* a window that
 * already exists. Idempotent by construction: every consumer upserts.
 *
 * Deliberately not `EventCreated`: several modules react to creation with
 * side effects a replay must not repeat.
 */
final class ReplayEventDetailsCommand extends Command
{
    protected $signature = 'event:replay-details
        {--event= : Replay one event by ULID instead of every event}
        {--chunk=200 : Events per database round trip}';

    protected $description = 'Re-emit EventRescheduled and EventDetailsUpdated for existing events so downstream read models can be rebuilt';

    public function handle(): int
    {
        $only = $this->option('event');
        $only = is_string($only) && $only !== '' ? $only : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $query = Event::withoutBrandScope();

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
