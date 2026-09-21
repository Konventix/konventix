<?php

declare(strict_types=1);

namespace Konventix\Event\Data\Blocks;

use Konventix\Event\Models\Event;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Server-resolved props for the `event-hero` block's Vue component.
 *
 * `startsAt`/`endsAt` are deliberately the wall-clock strings the organizer
 * promised (ADR-0027), not instants — the frontend prints them as-is rather
 * than reinterpreting them through `new Date(...)`, which would silently
 * shift them into the visitor's browser timezone.
 *
 * `slug` is the event's public landing slug, set only when the event
 * `isVisibleOnLanding()` — the hero becomes a link to the landing page then.
 * A draft's slug would 404 there (`EventLandingController`), so it stays
 * null and the hero renders as a plain section.
 */
#[TypeScript]
final class EventHeroResolvedData extends Data
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $name,
        public readonly ?string $startsAt,
        public readonly ?string $endsAt,
        public readonly ?string $timezone,
        public readonly ?string $venueCity,
        public readonly ?string $slug,
    ) {}

    /**
     * `$event` is null when the stored ULID no longer resolves — deleted, or
     * (thanks to `BelongsToBrand`'s global scope on the lookup) belonging to
     * a different brand. Either way this renders a graceful "not found"
     * shape instead of throwing and failing the whole page.
     */
    public static function forEvent(?Event $event): self
    {
        if ($event === null) {
            return new self(
                found: false,
                name: null,
                startsAt: null,
                endsAt: null,
                timezone: null,
                venueCity: null,
                slug: null,
            );
        }

        return new self(
            found: true,
            name: $event->name,
            startsAt: $event->starts_at->format('Y-m-d H:i:s'),
            endsAt: $event->ends_at->format('Y-m-d H:i:s'),
            timezone: $event->timezone,
            venueCity: $event->primaryVenue()?->city,
            slug: $event->isVisibleOnLanding() ? $event->slug : null,
        );
    }
}
