<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Events\CommentReacted;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentReaction;

/**
 * Likes a comment (ROADMAP slice 22). Idempotent per (user, comment): the
 * unique index is the truth, the count is recounted from it, and the event
 * fires only when a row actually appeared — a double click is one like.
 */
final class ReactToComment
{
    public function handle(ContentComment $comment, string $userId): ContentComment
    {
        return DB::transaction(function () use ($comment, $userId): ContentComment {
            $reaction = ContentReaction::query()->firstOrCreate([
                'user_id' => $userId,
                'module' => ContentReaction::MODULE_CONTENT,
                'entity_type' => ContentReaction::ENTITY_COMMENT,
                'entity_id' => $comment->getKey(),
            ]);

            $comment->forceFill(['reaction_count' => ContentReaction::countFor($comment)])->save();

            if ($reaction->wasRecentlyCreated) {
                event(new CommentReacted(
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
