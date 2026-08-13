<?php

declare(strict_types=1);

namespace Konventix\Content\Events;

/**
 * Someone commented. `state` travels with it because a pre-moderating brand
 * produces a comment nobody can see yet, and a consumer counting visible
 * discussion must be able to tell the difference.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — the body is deliberately absent:
 * it is user-authored text with no business being copied into every listener.
 */
final readonly class CommentPosted
{
    public function __construct(
        public string $commentId,
        public string $entryId,
        public string $brandId,
        public string $authorUserId,
        public string $state,
        public string $postedAt,
    ) {}
}
