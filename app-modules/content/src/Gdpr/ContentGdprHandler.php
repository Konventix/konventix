<?php

declare(strict_types=1);

namespace Konventix\Content\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;

/**
 * Content's contribution to GDPR export and deletion.
 *
 * Deletion drops the author read model's display name — the only personal
 * data this module stores rather than references — and detaches the person's
 * *comments* from their name, without deleting the comment bodies.
 *
 * That is a decision, not an omission. A comment is a published contribution
 * to a conversation other people took part in; removing it silently rewrites
 * a thread that others replied to. Anonymizing the authorship is the usual
 * balance, and the body stays subject to moderation like any other. A person
 * who wants their words gone as well needs the operator to act on the
 * comment, which is what the moderation queue is for — noted in the TODO.
 */
final class ContentGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'content';
    }

    public function exportFor(string $subjectId, string $brandId): array
    {
        return [
            'comments' => ContentComment::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->where('author_user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (ContentComment $comment): array => [
                    'id' => $comment->getKey(),
                    'content_entry_id' => $comment->content_entry_id,
                    'body' => $comment->body,
                    'state' => $comment->state->value,
                    'posted_at' => $comment->created_at?->toIso8601String(),
                ])
                ->all(),
            'display_name' => Author::query()->whereKey($subjectId)->value('display_name'),
        ];
    }

    public function delete(string $subjectId, string $brandId, DeletionMode $mode): void
    {
        // The read model row survives with its ULID so a comment still has an
        // author to render — a tombstone, like everywhere else.
        Author::query()->whereKey($subjectId)->update(['display_name' => 'Deleted account']);
    }
}
