<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A plan document was saved and projected (design §9.4). Dispatched after
 * the commit, never inside the save transaction, so a listener always reads
 * the projection it announces. Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class PlanSaved
{
    /**
     * @param  list<string>  $changedPlaceIds
     */
    public function __construct(
        public string $planId,
        public string $eventId,
        public string $brandId,
        public int $version,
        public array $changedPlaceIds,
    ) {}
}
