<?php

declare(strict_types=1);

namespace Konventix\Content\Events;

/**
 * Someone withdrew a reaction. Dispatched once per removed row.
 */
final readonly class CommentReactionRemoved
{
    public function __construct(
        public string $commentId,
        public string $entryId,
        public string $brandId,
        public string $userId,
        public string $occurredAt,
    ) {}
}
