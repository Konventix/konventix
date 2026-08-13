<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * A device delivered a buffered batch of scans (ADR-0033). Emitted once per
 * successful submission by
 * `Konventix\Admission\Actions\SynchronizeScanBatch`.
 *
 * This is the observability seam for the offline model: `duplicates` counts
 * scans the server had already recorded, which is the normal shape of a
 * reconnection replay rather than a fault. A batch arriving with a large
 * `scannedThroughAt` lag is how an operator learns a lane was offline.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — counts, never the scans.
 */
final readonly class CheckInBatchSynchronized
{
    public function __construct(
        public string $deviceId,
        public string $eventId,
        public string $brandId,
        public int $accepted,
        public int $rejected,
        public int $duplicates,
        public string $synchronizedAt,
    ) {}
}
