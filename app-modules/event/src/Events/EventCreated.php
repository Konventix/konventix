<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * An event was created under a brand (SPEC.md "Public contract").
 * Scalars only (ADR-0004); wall-clock times + IANA zone (ADR-0027).
 *
 * `visibility` is `EventVisibility`'s backing value, carried as a scalar
 * because a consumer that *lists* events needs it (GitLab #240 branch review
 * blocker 2): `Unlisted` means reachable by direct link and never listed, a
 * promise a read model cannot keep without being told. Nullable and last so
 * the addition is additive for anything already constructing this.
 */
final readonly class EventCreated
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $slug,
        public string $name,
        public string $startsAt,
        public string $endsAt,
        public string $timezone,
        public ?string $predecessorEventId,
        public ?string $visibility = null,
        /** The banner's media-library ULID, null for none (#292). */
        public ?string $bannerMediaId = null,
    ) {}
}
