<?php

declare(strict_types=1);

namespace Konventix\Community\Gdpr;

use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityEventParticipation;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\CommunityReaction;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * Community's contribution to GDPR export and deletion: the profile-wall
 * shapes Task 9 built and ADR-0052's visibility/event-participation
 * additions (ADR-0003 — every module implements this from its own
 * ServiceProvider, never wired centrally).
 *
 * Community holds nothing keyed by a bare email address (accounts are
 * Identity's), so both methods return early for an email-only subject —
 * that is the honest no-op the contract calls for, not an error.
 *
 * Every model here is either operator-wide (`CommunityProfile`, no brand
 * scope to escape) or brand-owned (`BelongsToBrand`); the brand-owned ones
 * are queried with `withoutBrandScope()` because this runs off any host,
 * with no ambient brand in context (.ai/rules/app-modules-src.md).
 *
 * **Deletion order matters.** A comment's `reaction_count` must never go
 * stale, and a reaction must never survive the comment it points at:
 *
 * 1. The subject's own reactions go first, and every comment they sat on
 *    that survives is recounted — mirrors `ContentGdprHandler`.
 * 2. Comments the subject authored are removed next, but only after the
 *    reactions *other people* left on those comments are deleted first —
 *    otherwise those rows would dangle, pointing at a comment that no
 *    longer exists.
 * 3. Event participation, brand membership, and finally the profile row
 *    itself — the read model with nothing left pointing at it.
 *
 * Both methods are idempotent: a second call finds nothing left to touch.
 */
final class CommunityGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'community';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        /** @var CommunityProfile|null $profile */
        $profile = CommunityProfile::query()->find($subjectId);

        return [
            'profile' => $profile === null ? null : [
                'username' => $profile->username,
                'display_name' => $profile->display_name,
                'tagline' => $profile->tagline,
                'status_emoji' => $profile->status_emoji,
                'social_links' => $profile->social_links,
                'visibility' => $profile->visibility,
            ],
            'memberships' => CommunityMember::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('member_since')
                ->get()
                ->map(fn (CommunityMember $member): array => [
                    'brand_id' => $member->brand_id,
                    'member_since' => $member->member_since->toIso8601String(),
                    'avatar_url' => $member->avatar_url,
                    'banner_url' => $member->banner_url,
                ])
                ->all(),
            'comments' => CommunityComment::withoutBrandScope()
                ->where('author_user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CommunityComment $comment): array => [
                    'id' => $comment->getKey(),
                    'brand_id' => $comment->brand_id,
                    'module' => $comment->module,
                    'entity_type' => $comment->entity_type,
                    'entity_id' => $comment->entity_id,
                    'body' => $comment->body,
                    'posted_at' => $comment->created_at?->toIso8601String(),
                ])
                ->all(),
            'reactions' => CommunityReaction::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CommunityReaction $reaction): array => [
                    'brand_id' => $reaction->brand_id,
                    'module' => $reaction->module,
                    'entity_type' => $reaction->entity_type,
                    'entity_id' => $reaction->entity_id,
                    'reacted_at' => $reaction->created_at?->toIso8601String(),
                ])
                ->all(),
            'event_participations' => CommunityEventParticipation::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CommunityEventParticipation $participation): array => [
                    'brand_id' => $participation->brand_id,
                    'event_id' => $participation->event_id,
                    'confirmed' => $participation->confirmed,
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        $subjectId = $subject->userId;

        // The subject's own reactions go first, and every surviving comment
        // they sat on is recounted from the rows that are left — see the
        // class docblock for why the order matters.
        $reactions = CommunityReaction::withoutBrandScope()
            ->where('user_id', $subjectId)
            ->get();

        $reactedCommentIds = $reactions
            ->filter(fn (CommunityReaction $reaction): bool => $reaction->entity_type === CommunityReaction::ENTITY_COMMENT)
            ->pluck('entity_id');

        CommunityReaction::withoutBrandScope()->whereKey($reactions->pluck('id'))->delete();

        CommunityComment::withoutBrandScope()->whereKey($reactedCommentIds)->get()
            ->each(fn (CommunityComment $comment) => $comment->forceFill(['reaction_count' => CommunityReaction::countFor($comment)])->save());

        // Comments the subject authored: the reactions other people left on
        // them are removed first so nothing is left dangling, then the
        // comments themselves.
        $authoredCommentIds = CommunityComment::withoutBrandScope()
            ->where('author_user_id', $subjectId)
            ->pluck('id');

        CommunityReaction::withoutBrandScope()
            ->where('module', CommunityComment::MODULE)
            ->where('entity_type', CommunityReaction::ENTITY_COMMENT)
            ->whereIn('entity_id', $authoredCommentIds)
            ->delete();

        CommunityComment::withoutBrandScope()->whereKey($authoredCommentIds)->delete();

        CommunityEventParticipation::withoutBrandScope()->where('user_id', $subjectId)->delete();
        CommunityMember::withoutBrandScope()->where('user_id', $subjectId)->delete();
        CommunityProfile::query()->whereKey($subjectId)->delete();
    }
}
