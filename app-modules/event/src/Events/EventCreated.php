<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * An event was created under a brand (SPEC.md "Public contract").
 * Scalars only (ADR-0004); wall-clock times + IANA zone (ADR-0027).
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
    ) {}
}
