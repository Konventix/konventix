<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A plan was withdrawn from the picker (design §6.4, §9.4). Existing
 * bookings stay; only new picks stop. Payload is scalars/ULIDs only
 * (ADR-0004).
 */
final readonly class PlanUnpublished
{
    public function __construct(
        public string $planId,
        public string $eventId,
        public string $brandId,
    ) {}
}
