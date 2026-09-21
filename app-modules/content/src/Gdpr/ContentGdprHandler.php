<?php

declare(strict_types=1);

namespace Konventix\Content\Gdpr;

use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;
use Konventix\Content\Models\Author;
use Konventix\Content\Models\ContentComment;
use Konventix\Content\Models\ContentReaction;

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

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        return [
            'comments' => ContentComment::withoutBrandScope()
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
            'reactions' => ContentReaction::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (ContentReaction $reaction): array => [
                    'module' => $reaction->module,
                    'entity_type' => $reaction->entity_type,
                    'entity_id' => $reaction->entity_id,
                    'reacted_at' => $reaction->created_at?->toIso8601String(),
                ])
                ->all(),
            'display_name' => Author::query()->whereKey($subjectId)->value('display_name'),
            'username' => Author::query()->whereKey($subjectId)->value('username'),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        $subjectId = $subject->userId;

        // The read model row survives with its ULID so a comment still has an
        // author to render — a tombstone, like everywhere else. The handle
        // goes with it (ADR-0065 point 5): Identity frees it on
        // anonymization, and a stale one here would print — or link to —
        // whoever takes it next.
        Author::query()->whereKey($subjectId)->update(['display_name' => 'Deleted account', 'username' => null]);

        // A reaction is an opinion, not retained data: gone in both modes,
        // and the comments it counted towards are recounted.
        $reactions = ContentReaction::withoutBrandScope()
            ->where('user_id', $subjectId)
            ->get();

        $commentIds = $reactions
            ->filter(fn (ContentReaction $reaction): bool => $reaction->entity_type === ContentReaction::ENTITY_COMMENT)
            ->pluck('entity_id');

        ContentReaction::withoutBrandScope()->whereKey($reactions->pluck('id'))->delete();

        ContentComment::withoutBrandScope()->whereKey($commentIds)->get()
            ->each(fn (ContentComment $comment) => $comment->forceFill(['reaction_count' => ContentReaction::countFor($comment)])->save());
    }
}
