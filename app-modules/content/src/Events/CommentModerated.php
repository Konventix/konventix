<?php

declare(strict_types=1);

namespace Konventix\Content\Events;

/**
 * A moderator decided about a comment — approved, rejected or hidden.
 *
 * One event with the resulting state rather than three: every consumer cares
 * that the visible set changed, and splitting it would make each of them
 * handle three events to learn one fact.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class CommentModerated
{
    public function __construct(
        public string $commentId,
        public string $entryId,
        public string $brandId,
        public string $state,
        public ?string $moderatedByUserId,
        public string $moderatedAt,
    ) {}
}
