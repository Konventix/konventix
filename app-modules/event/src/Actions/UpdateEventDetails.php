<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Data\EventData;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Models\Event;

/**
 * Updates an event's details from the form Data (ADR-0009). Emits
 * `EventDetailsUpdated` for display changes and — when the schedule
 * actually moved — `EventRescheduled`, the event half the system cares
 * about (SPEC.md: it invalidates schedules, shift plans, bookings).
 */
final class UpdateEventDetails
{
    public function handle(Event $event, EventData $data): Event
    {
        return DB::transaction(function () use ($event, $data): Event {
            $this->guardSlugUnique($event, $data->slug);

            $rescheduled = $event->starts_at->format('Y-m-d H:i:s') !== $data->startsAt
                || $event->ends_at->format('Y-m-d H:i:s') !== $data->endsAt
                || $event->timezone !== $data->timezone;

            $event->forceFill([
                'name' => $data->name,
                'slug' => $data->slug,
                'description' => $data->description,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'timezone' => $data->timezone,
                'visibility' => $data->visibility,
                'reentry_policy' => $data->reentryPolicy,
                'predecessor_event_id' => $data->predecessorEventId,
            ])->save();

            event(new EventDetailsUpdated(
                eventId: $event->id,
                brandId: $event->brand_id,
                slug: $event->slug,
                name: $event->name,
            ));

            if ($rescheduled) {
                event(new EventRescheduled(
                    eventId: $event->id,
                    startsAt: $event->starts_at->format('Y-m-d H:i:s'),
                    endsAt: $event->ends_at->format('Y-m-d H:i:s'),
                    timezone: $event->timezone,
                ));
            }

            return $event->refresh();
        });
    }

    private function guardSlugUnique(Event $event, string $slug): void
    {
        $taken = Event::withoutBrandScope()
            ->where('brand_id', $event->brand_id)
            ->where('slug', $slug)
            ->whereKeyNot($event->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('This slug is already used by another event of this brand.'),
            ]);
        }
    }
}
