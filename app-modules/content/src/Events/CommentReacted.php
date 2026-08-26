<?php

declare(strict_types=1);

namespace Konventix\Content\Events;

/**
 * Someone liked a comment (ROADMAP slice 22). Dispatched once per new
 * reaction row, never for a repeated click. Payload is scalars/ULIDs only
 * (ADR-0004).
 */
final readonly class CommentReacted
{
    public function __construct(
        public string $commentId,
        public string $entryId,
        public string $brandId,
        public string $userId,
        public string $occurredAt,
    ) {}
}
