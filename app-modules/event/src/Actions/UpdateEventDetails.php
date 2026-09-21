<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Data\EventData;
use Konventix\Event\Events\EventAttendeeListPolicyChanged;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventReentryPolicyChanged;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Models\Event;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Updates an event's details from the form Data (ADR-0009). Emits
 * `EventDetailsUpdated` for display changes and — when the schedule
 * actually moved — `EventRescheduled`, the event half the system cares
 * about (SPEC.md: it invalidates schedules, shift plans, bookings).
 */
final class UpdateEventDetails
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(Event $event, EventData $data): Event
    {
        return DB::transaction(function () use ($event, $data): Event {
            $this->guardSlugUnique($event, $data->slug);
            $this->guardOwnedBanner($event->brand_id, $data->bannerMediaId);

            $previousBanner = $event->banner_media_id;

            $rescheduled = $event->starts_at->format('Y-m-d H:i:s') !== $data->startsAt
                || $event->ends_at->format('Y-m-d H:i:s') !== $data->endsAt
                || $event->timezone !== $data->timezone;

            $reentryPolicyChanged = $event->reentry_policy !== $data->reentryPolicy;
            $attendeeListPolicyChanged = $event->attendee_list_visibility !== $data->attendeeListVisibility;

            $event->forceFill([
                'name' => $data->name,
                'slug' => $data->slug,
                'description' => $data->description,
                'banner_media_id' => $data->bannerMediaId,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'timezone' => $data->timezone,
                'visibility' => $data->visibility,
                'reentry_policy' => $data->reentryPolicy,
                'attendee_list_visibility' => $data->attendeeListVisibility,
                'predecessor_event_id' => $data->predecessorEventId,
            ])->save();

            $this->syncBannerUsage($event, $previousBanner, $event->banner_media_id);

            event(new EventDetailsUpdated(
                eventId: $event->id,
                brandId: $event->brand_id,
                slug: $event->slug,
                name: $event->name,
                visibility: $event->visibility->value,
                bannerMediaId: $event->banner_media_id,
            ));

            if ($rescheduled) {
                event(new EventRescheduled(
                    eventId: $event->id,
                    startsAt: $event->starts_at->format('Y-m-d H:i:s'),
                    endsAt: $event->ends_at->format('Y-m-d H:i:s'),
                    timezone: $event->timezone,
                    brandId: $event->brand_id,
                ));
            }

            // Admission holds this locally so the door can evaluate re-entry
            // offline (ADR-0033); it has no other way to learn it (ADR-0004).
            if ($reentryPolicyChanged) {
                event(new EventReentryPolicyChanged(
                    eventId: $event->id,
                    brandId: $event->brand_id,
                    reentryPolicy: $event->reentry_policy->value,
                ));
            }

            // Registration renders the attendee list off its projection of
            // this switch (ADR-0064 §6) and may not read `events`.
            if ($attendeeListPolicyChanged) {
                event(new EventAttendeeListPolicyChanged(
                    eventId: $event->id,
                    brandId: $event->brand_id,
                    attendeeListVisibility: $event->attendee_list_visibility->value,
                ));
            }

            return $event->refresh();
        });
    }

    /**
     * The library tracks who wears a picture so it can refuse to delete one
     * still in use (ADR-0035) — the same bookkeeping `UpdateBrand` keeps for
     * the brand's marks. Recorded under this module's own name.
     */
    private function syncBannerUsage(Event $event, ?string $before, ?string $after): void
    {
        if ($before === $after) {
            return;
        }

        if ($before !== null) {
            $this->media->releaseUsage($before, 'event', 'event', $event->id);
        }

        if ($after !== null) {
            $this->media->recordUsage($after, 'event', 'event', $event->id);
        }
    }

    /**
     * A ULID from another brand's library would resolve and render — the
     * library ignores brand scope on read — and vanish the day that brand
     * is deleted (`UpdateBrand::guardOwnedMedia()` says the same).
     */
    private function guardOwnedBanner(string $brandId, ?string $mediaId): void
    {
        if ($mediaId !== null && ! $this->media->belongsToBrand($mediaId, $brandId)) {
            throw ValidationException::withMessages([
                'bannerMediaId' => __('That file belongs to a different brand.'),
            ]);
        }
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
