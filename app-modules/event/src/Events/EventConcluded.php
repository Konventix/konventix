<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event has ended (SPEC.md "Public contract" table). Emitted by
 * `Konventix\Event\Actions\ConcludeEvent`. This is what starts retention
 * clocks in Compliance.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class EventConcluded
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $concludedAt,
    ) {}
}
