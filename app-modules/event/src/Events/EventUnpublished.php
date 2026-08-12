<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event was withdrawn from public view (SPEC.md "Public contract"
 * table). Emitted by `Konventix\Event\Actions\UnpublishEvent`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class EventUnpublished
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $brandId,
    ) {}
}
