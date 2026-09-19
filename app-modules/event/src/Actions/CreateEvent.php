<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Data\EventData;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventReentryPolicyChanged;
use Konventix\Event\Models\Event;
use Konventix\Platform\Media\MediaLibrary;

/**
 * Creates an event under an explicit brand (SPEC.md "Public contract" —
 * `EventCreated`). The brand ULID is a parameter, not ambient context:
 * creation happens on the operator admin host, which resolves no brand
 * (ADR-0029, same pattern as media uploads).
 *
 * Slug uniqueness is enforced here per brand (the TODO.md gap): the DB
 * index is per brand already, this turns the constraint violation into a
 * form error.
 */
final class CreateEvent
{
    public function __construct(
        private readonly SyncEventDays $syncEventDays,
        private readonly MediaLibrary $media,
    ) {}

    /**
     * @param  list<array{date: string, label: string|null}>  $days
     */
    public function handle(string $brandId, EventData $data, array $days = []): Event
    {
        return DB::transaction(function () use ($brandId, $data, $days): Event {
            $this->guardSlugUnique($brandId, $data->slug);
            $this->guardOwnedBanner($brandId, $data->bannerMediaId);

            $event = new Event([
                'name' => $data->name,
                'slug' => $data->slug,
                'description' => $data->description,
                'banner_media_id' => $data->bannerMediaId,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'timezone' => $data->timezone,
                'visibility' => $data->visibility,
                'reentry_policy' => $data->reentryPolicy,
                'predecessor_event_id' => $data->predecessorEventId,
            ]);
            // Explicit brand, not ambient context (ADR-0029).
            $event->brand_id = $brandId;
            $event->save();

            if ($event->banner_media_id !== null) {
                $this->media->recordUsage($event->banner_media_id, 'event', 'event', $event->id);
            }

            event(new EventCreated(
                eventId: $event->id,
                brandId: $event->brand_id,
                slug: $event->slug,
                name: $event->name,
                startsAt: $event->starts_at->format('Y-m-d H:i:s'),
                endsAt: $event->ends_at->format('Y-m-d H:i:s'),
                timezone: $event->timezone,
                predecessorEventId: $event->predecessor_event_id,
                visibility: $event->visibility->value,
                bannerMediaId: $event->banner_media_id,
            ));

            // Emitted at creation, not only on change: a consumer that hears
            // only about *changes* never learns the policy of an event whose
            // policy was correct from the start (ADR-0033 needs it locally).
            event(new EventReentryPolicyChanged(
                eventId: $event->id,
                brandId: $event->brand_id,
                reentryPolicy: $event->reentry_policy->value,
            ));

            if ($days !== []) {
                $event = $this->syncEventDays->handle($event, $days);
            }

            return $event;
        });
    }

    /**
     * Mirrors `UpdateEventDetails::guardOwnedBanner()`.
     */
    private function guardOwnedBanner(string $brandId, ?string $mediaId): void
    {
        if ($mediaId !== null && ! $this->media->belongsToBrand($mediaId, $brandId)) {
            throw ValidationException::withMessages([
                'bannerMediaId' => __('That file belongs to a different brand.'),
            ]);
        }
    }

    private function guardSlugUnique(string $brandId, string $slug): void
    {
        $taken = Event::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('slug', $slug)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'slug' => __('This slug is already used by another event of this brand.'),
            ]);
        }
    }
}
