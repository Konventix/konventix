<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Konventix\Content\Models\ContentComment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One approved comment as a reader sees it (ADR-0009).
 *
 * The author is a display name from Content's own read model — the account
 * ULID never leaves the server, so a public page cannot be scraped for
 * account identifiers. `isMine` lets the page mark the reader's own comment
 * without shipping ids to compare.
 */
#[TypeScript]
final class PublicCommentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $body,
        public readonly string $authorName,
        public readonly string $postedAt,
        public readonly bool $isMine,
    ) {}

    public static function ofModel(ContentComment $comment, ?string $authorName, ?string $viewerId): self
    {
        return new self(
            id: $comment->getKey(),
            body: $comment->body,
            // A commenter whose account predates the read model still gets a
            // byline rather than a blank one.
            authorName: $authorName ?? __('Participant'),
            postedAt: $comment->created_at?->toIso8601String() ?? now()->toIso8601String(),
            isMine: $viewerId !== null && $comment->author_user_id === $viewerId,
        );
    }
}
