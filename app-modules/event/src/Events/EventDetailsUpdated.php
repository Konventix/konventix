<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * Name, slug, description or visibility changed (SPEC.md "Public contract")
 * — display data for read models. Schedule changes emit `EventRescheduled`
 * instead.
 *
 * `visibility` is `EventVisibility`'s backing value (#240 branch review
 * blocker 2): `UpdateEventDetails` writes the column, so a read model that
 * lists events has to hear about it here or it keeps advertising an event
 * that has just been made `Unlisted`. Nullable and last — additive.
 */
final readonly class EventDetailsUpdated
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $slug,
        public string $name,
        public ?string $visibility = null,
        /** The banner's media-library ULID, null for none (#292). Also additive. */
        public ?string $bannerMediaId = null,
    ) {}
}
