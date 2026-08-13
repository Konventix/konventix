<?php

declare(strict_types=1);

namespace Konventix\Content\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Content\Enums\CommentState;
use Konventix\Content\Events\CommentModerated;
use Konventix\Content\Models\ContentComment;

/**
 * Approves, rejects or hides a comment.
 *
 * Rejecting and hiding are kept apart deliberately: rejecting turns down a
 * submission nobody saw, hiding removes something that was already public.
 * A moderator reviewing their own history needs to know which they did.
 *
 * Reporting only increments a counter — what a moderator needs is a queue
 * ordered by attention, not a forensic record of who clicked the flag.
 */
final class ModerateComment
{
    public function handle(ContentComment $comment, CommentState $to, ?string $moderatorUserId = null): ContentComment
    {
        return DB::transaction(function () use ($comment, $to, $moderatorUserId): ContentComment {
            if ($comment->state === $to) {
                return $comment;
            }

            $comment->forceFill([
                'state' => $to,
                'moderated_by_user_id' => $moderatorUserId,
                'moderated_at' => now(),
            ])->save();

            event(new CommentModerated(
                commentId: $comment->id,
                entryId: $comment->content_entry_id,
                brandId: $comment->brand_id,
                state: $to->value,
                moderatedByUserId: $moderatorUserId,
                moderatedAt: now()->toIso8601String(),
            ));

            return $comment->refresh();
        });
    }

    public function report(ContentComment $comment): ContentComment
    {
        $comment->increment('report_count');

        return $comment->refresh();
    }
}
