<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Konventix\Content\Models\ContentComment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One approved comment as a reader sees it (ADR-0009).
 *
 * The author is a handle from Content's own read model (ADR-0065): the
 * page prints `@username`, never the display name, and links it to
 * `authorProfileUrl` when the server computed one — Community active for
 * the brand, so `/u/{username}` exists. The account ULID never leaves the
 * server, so a public page cannot be scraped for account identifiers.
 * `isMine` lets the page mark the reader's own comment without shipping
 * ids to compare. `hasReacted` is viewer-relative and computed
 * server-side for the same reason `isMine` is.
 */
#[TypeScript]
final class PublicCommentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $body,
        /** Null for an account the projection has not seen; the page then says "Participant". */
        public readonly ?string $authorUsername,
        public readonly ?string $authorProfileUrl,
        public readonly string $postedAt,
        public readonly bool $isMine,
        public readonly int $reactionCount,
        public readonly bool $hasReacted,
    ) {}

    public static function ofModel(ContentComment $comment, ?string $authorUsername, ?string $authorProfileUrl, ?string $viewerId, bool $hasReacted = false): self
    {
        return new self(
            id: $comment->getKey(),
            body: $comment->body,
            authorUsername: $authorUsername,
            authorProfileUrl: $authorProfileUrl,
            postedAt: $comment->created_at?->toIso8601String() ?? now()->toIso8601String(),
            isMine: $viewerId !== null && $comment->author_user_id === $viewerId,
            reactionCount: $comment->reaction_count,
            hasReacted: $hasReacted,
        );
    }
}
