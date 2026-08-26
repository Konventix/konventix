<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Events\CommentReactionRemoved;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentReaction;

/**
 * Withdraws a like. Removing nothing is a no-op with no event — a second
 * "unlike" must not tell listeners something happened twice.
 */
final class UnreactToComment
{
    public function handle(ContentComment $comment, string $userId): ContentComment
    {
        return DB::transaction(function () use ($comment, $userId): ContentComment {
            $removed = ContentReaction::query()
                ->where('user_id', $userId)
                ->forSubject(ContentReaction::MODULE_CONTENT, ContentReaction::ENTITY_COMMENT, $comment->getKey())
                ->delete();

            $comment->forceFill(['reaction_count' => ContentReaction::countFor($comment)])->save();

            if ($removed > 0) {
                event(new CommentReactionRemoved(
                    commentId: $comment->getKey(),
                    entryId: $comment->content_entry_id,
                    brandId: $comment->brand_id,
                    userId: $userId,
                    occurredAt: now()->toIso8601String(),
                ));
            }

            return $comment->refresh();
        });
    }
}
