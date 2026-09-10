<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A plan became visible to the picker (design §6.4, §9.4). Payload is
 * scalars/ULIDs only (ADR-0004).
 */
final readonly class PlanPublished
{
    public function __construct(
        public string $planId,
        public string $eventId,
        public string $brandId,
    ) {}
}
