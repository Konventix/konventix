<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The event moved Published → Running — doors are open (SPEC.md "Event
 * lifecycle"). An explicit, operator-triggered transition rather than a
 * computed "now between starts/ends" state: GameOps/Admission behavior
 * changes when the operator says the event runs, not when a clock ticks
 * past a promise (see TODO.md's derived-state note, settled this way).
 */
final readonly class EventStarted
{
    public function __construct(
        public string $eventId,
        public string $brandId,
        public string $startedAt,
    ) {}
}
