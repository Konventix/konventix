<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when every registered handler has discharged a deletion request
 * (SPEC "Public contract").
 *
 * "Completed" means every handler ran and reported success — not that no
 * trace of the person remains. Anonymize-with-tombstone is the default mode
 * (Round 6 D-BATCH): rows survive with their ULIDs so foreign references
 * stay resolvable, and statutory retention (an issued invoice, §147 AO) can
 * outlive the request entirely.
 */
final class UserDeletionCompleted
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $subjectId,
        public readonly string $brandId,
        public readonly string $completedAt,
    ) {}
}
