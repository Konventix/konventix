<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Events\CommentPosted;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentEntry;
use RuntimeException;

/**
 * Posts a comment on an entry.
 *
 * `$authorUserId` is required, not optional: comments require an account
 * (D-BATCH-7), so there is no anonymous path to guard against — there simply
 * is not one.
 *
 * Refuses on an entry that is not live or has comments switched off. Both are
 * checked here rather than only in a controller, because a comment arriving
 * by any other route must obey the same rule.
 *
 * New comments land `Approved` unless the brand pre-moderates. Pre-moderation
 * as the default would mean an empty comment section at every event until
 * someone remembered to check a queue.
 */
final class PostComment
{
    public function handle(
        ContentEntry $entry,
        string $authorUserId,
        string $body,
        bool $preModerate = false,
    ): ContentComment {
        return DB::transaction(function () use ($entry, $authorUserId, $body, $preModerate): ContentComment {
            if (! $entry->comments_enabled) {
                throw new RuntimeException(sprintf('Comments are disabled on entry [%s].', $entry->id));
            }

            if (! $entry->isLive()) {
                throw new RuntimeException(sprintf('Entry [%s] is not published; it cannot be commented on.', $entry->id));
            }

            $comment = new ContentComment([
                'content_entry_id' => $entry->id,
                'author_user_id' => $authorUserId,
                'body' => $body,
                'state' => $preModerate ? CommentState::Pending : CommentState::Approved,
            ]);
            // brand_id from the entry, not ambient context (ADR-0010).
            $comment->forceFill(['brand_id' => $entry->brand_id])->save();

            event(new CommentPosted(
                commentId: $comment->id,
                entryId: $entry->id,
                brandId: $entry->brand_id,
                authorUserId: $authorUserId,
                state: $comment->state->value,
                postedAt: $comment->created_at->toIso8601String(),
            ));

            return $comment;
        });
    }
}
