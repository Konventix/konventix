<?php

declare(strict_types=1);

namespace Konventix\Community\Gdpr;

use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityEventParticipation;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\CommunityReaction;
use Konventix\Community\Models\EventPresence;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Models\RoomMute;
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
 * **The event shoutbox** (design §7) follows the same line, in four
 * moves the spec names and one it implies:
 *
 * - **Messages the subject authored are deleted outright** — a shoutbox
 *   line is not a business record — and the reports anybody filed on them
 *   go first, because a report about a line that no longer exists has
 *   nothing left to be a fact about.
 * - **Reports the subject filed stay, anonymised**: `reporter_user_id` is
 *   nulled, and so is their `note`, which is the subject's own words. The
 *   report is kept as a fact about the message it names — `ResourcesGdprHandler`
 *   nulls `holder_user_id` on the same reasoning.
 * - **`community_event_presence`**, the admission badge, is exported and
 *   deleted with the subject.
 * - **Their ULID in a moderator column on somebody else's row is nulled,
 *   and the row stays**: who removed a line (`deleted_by_user_id`), who
 *   locked a room (`locked_by_user_id`), who muted somebody
 *   (`muted_by_user_id`), who resolved a report (`resolved_by_user_id`).
 *   The act is a fact about the other person's line or room; who did it is
 *   the subject's.
 * - **Mutes held against the subject are exported and deleted.** The row
 *   is about nobody else, and the account it silences is being erased.
 *
 * The connection state — online, away, active in a room — is never on
 * disk (ADR-0067 decision 3), so there is nothing here for it: it is a
 * Redis key with a TTL and lapses on its own.
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
            ...$this->exportShoutbox($subjectId),
        ];
    }

    /**
     * The event shoutbox's share of the export (design §7): the subject's
     * own lines — a removed one too, with when it was removed but not by
     * whom — the reports they filed, the mutes held against them, and their
     * admission badges. Somebody else's ULID never appears here.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function exportShoutbox(string $subjectId): array
    {
        return [
            'shoutbox_messages' => Message::withoutBrandScope()
                ->where('author_user_id', $subjectId)
                ->orderBy('id')
                ->get()
                ->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'brand_id' => $message->brand_id,
                    'room_id' => $message->room_id,
                    'body' => $message->body,
                    'posted_at' => $message->created_at?->toIso8601String(),
                    'removed_at' => $message->deleted_at?->toIso8601String(),
                ])
                ->all(),
            'shoutbox_reports' => MessageReport::withoutBrandScope()
                ->where('reporter_user_id', $subjectId)
                ->orderBy('id')
                ->get()
                ->map(fn (MessageReport $report): array => [
                    'id' => $report->id,
                    'brand_id' => $report->brand_id,
                    'message_id' => $report->message_id,
                    'note' => $report->note,
                    'state' => $report->state->value,
                    'reported_at' => $report->created_at?->toIso8601String(),
                ])
                ->all(),
            'shoutbox_mutes' => RoomMute::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('id')
                ->get()
                ->map(fn (RoomMute $mute): array => [
                    'brand_id' => $mute->brand_id,
                    'room_id' => $mute->room_id,
                    'reason' => $mute->reason,
                    'muted_at' => $mute->created_at?->toIso8601String(),
                    'expires_at' => $mute->expires_at?->toIso8601String(),
                ])
                ->all(),
            'event_presence' => EventPresence::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('id')
                ->get()
                ->map(fn (EventPresence $presence): array => [
                    'brand_id' => $presence->brand_id,
                    'event_id' => $presence->event_id,
                    'checked_in' => $presence->checked_in,
                    'last_direction' => $presence->last_direction,
                    'updated_at' => $presence->updated_at?->toIso8601String(),
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

        $this->eraseShoutbox($subjectId);

        CommunityEventParticipation::withoutBrandScope()->where('user_id', $subjectId)->delete();
        CommunityMember::withoutBrandScope()->where('user_id', $subjectId)->delete();
        CommunityProfile::query()->whereKey($subjectId)->delete();
    }

    /**
     * See the class docblock. Query-level `update()`s, not `fill()`: the
     * actor columns of the tombstone, lock and resolve triples are off
     * `$fillable` precisely so that nothing sets them in passing, and
     * nulling one here is the deliberate act that rule exists to force.
     */
    private function eraseShoutbox(string $subjectId): void
    {
        $authored = Message::withoutBrandScope()->where('author_user_id', $subjectId)->select('id');

        MessageReport::withoutBrandScope()->whereIn('message_id', $authored)->delete();
        Message::withoutBrandScope()->where('author_user_id', $subjectId)->delete();

        MessageReport::withoutBrandScope()
            ->where('reporter_user_id', $subjectId)
            ->update(['reporter_user_id' => null, 'note' => null]);
        MessageReport::withoutBrandScope()
            ->where('resolved_by_user_id', $subjectId)
            ->update(['resolved_by_user_id' => null]);
        Message::withoutBrandScope()
            ->where('deleted_by_user_id', $subjectId)
            ->update(['deleted_by_user_id' => null]);
        Room::withoutBrandScope()
            ->where('locked_by_user_id', $subjectId)
            ->update(['locked_by_user_id' => null]);
        RoomMute::withoutBrandScope()
            ->where('muted_by_user_id', $subjectId)
            ->update(['muted_by_user_id' => null]);

        RoomMute::withoutBrandScope()->where('user_id', $subjectId)->delete();
        EventPresence::withoutBrandScope()->where('user_id', $subjectId)->delete();
    }
}
