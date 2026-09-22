<?php

declare(strict_types=1);

namespace Konventix\Platform\Listeners;

use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Platform\Hub\HubLayoutResolver;
use Konventix\Platform\Models\HubLayout;

/**
 * Keeps `platform_hub_layouts` in step with Event's `EventHubLayoutChanged`
 * (design §6, ADR-0004) — Platform's only knowledge of how the operator
 * composed an event's hub. Registered in
 * `PlatformServiceProvider::registerListeners()`.
 *
 * Idempotent: the event carries the whole layout and the row is keyed by
 * `event_id`, so a redelivery writes the same row again. Ends by forgetting
 * the resolver's cache entry so the next render sees the change rather
 * than the TTL.
 *
 * The event may arrive from a request with no ambient brand (the admin
 * host, ADR-0029) or from a queue worker with none at all, so the query
 * escapes the brand scope by the payload's own brand id (ADR-0010), and
 * `brand_id` on a new row is set through `forceFill()` — it is never
 * fillable.
 */
final class ProjectHubLayout
{
    public function handle(EventHubLayoutChanged $event): void
    {
        $row = HubLayout::withoutBrandScope()
            ->where('brand_id', $event->brandId)
            ->where('event_id', $event->eventId)
            ->first()
            ?? (new HubLayout)->forceFill(['brand_id' => $event->brandId]);

        $row->fill([
            'event_id' => $event->eventId,
            'cards' => $event->cards,
            'hub_replaces_home' => $event->hubReplacesHome,
        ])->save();

        HubLayoutResolver::forget($event->eventId);
    }
}
