<?php

declare(strict_types=1);

namespace Konventix\Community\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Community\Http\Concerns\ResolvesProfiles;
use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\CommunityReaction;

/**
 * `GET /u/{username}` — Community's first routed page, the brand-hosted
 * account page ADR-0051 §7 promised and ADR-0052 gave a visibility gate to.
 *
 * The full gate lives in `ResolvesProfiles` (shared with Task 9's comments
 * surface, which reuses `viewerIsMember` to decide comment-eligibility —
 * ADR-0052 §2, comments stay internal-scope regardless of the page's own
 * visibility). `comments`/`canComment` are computed ONLY when the viewer
 * passes that same internal check: a guest or non-member on a `public`
 * profile gets `comments: null` and the page renders no comments section at
 * all — the write endpoints 404 them the same way (Task 9).
 */
final class ProfileController
{
    use ResolvesProfiles;

    public function show(Request $request, string $username): Response
    {
        ['profile' => $profile, 'member' => $member, 'viewerIsMember' => $viewerIsMember] = $this->resolveProfileOrFail($request, $username);

        $viewerId = $request->user()?->getAuthIdentifier();
        $viewerId = $viewerId === null ? null : (string) $viewerId;

        return Inertia::render('community/Profile', [
            'profile' => [
                'username' => $profile->username,
                'displayName' => $profile->display_name,
                'tagline' => $profile->tagline,
                'statusEmoji' => $profile->status_emoji,
                'socialLinks' => $profile->social_links ?? (object) [],
                'avatarUrl' => $member->avatar_url,
                'bannerUrl' => $member->banner_url,
                'memberSince' => $member->member_since->toDateString(),
                'isSelf' => $viewerId !== null && $profile->getKey() === $viewerId,
            ],
            'viewerIsMember' => $viewerIsMember,
            'comments' => $viewerIsMember ? $this->presentComments($profile, $viewerId) : null,
            'canComment' => $viewerIsMember,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentComments(CommunityProfile $profile, ?string $viewerId): array
    {
        $comments = CommunityComment::query()
            ->forSubject(CommunityComment::MODULE, CommunityComment::ENTITY_PROFILE, $profile->getKey())
            ->orderBy('created_at')
            ->get();

        if ($comments->isEmpty()) {
            return [];
        }

        // Two lookups for the whole page rather than one per row — the same
        // "keyed fetch beats a join" precedent `MyRegistrationsController`
        // and Content's `ContentController::show` follow.
        $authorIds = $comments->pluck('author_user_id')->unique()->all();

        $authors = $this->authorsById($authorIds);

        $reacted = $viewerId === null ? [] : CommunityReaction::query()
            ->where('user_id', $viewerId)
            ->where('module', CommunityComment::MODULE)
            ->where('entity_type', CommunityReaction::ENTITY_COMMENT)
            ->whereIn('entity_id', $comments->pluck('id'))
            ->pluck('entity_id')
            ->all();

        $wallOwnerId = $profile->getKey();

        return $comments
            ->map(function (CommunityComment $comment) use ($authors, $viewerId, $reacted, $wallOwnerId): array {
                $author = $authors[$comment->author_user_id] ?? null;

                return [
                    'id' => $comment->getKey(),
                    'body' => $comment->body,
                    'authorName' => $author['displayName'] ?? __('Member'),
                    'authorUsername' => $author['username'] ?? null,
                    'postedAt' => $comment->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'isMine' => $viewerId !== null && $comment->author_user_id === $viewerId,
                    'canDelete' => $viewerId !== null && ($comment->author_user_id === $viewerId || $viewerId === $wallOwnerId),
                    'reactionCount' => $comment->reaction_count,
                    'hasReacted' => in_array($comment->getKey(), $reacted, true),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, array{displayName: string, username: string}>
     */
    private function authorsById(array $ids): array
    {
        return CommunityProfile::query()
            ->whereKey($ids)
            ->get()
            ->mapWithKeys(fn (CommunityProfile $profile): array => [
                $profile->getKey() => ['displayName' => $profile->display_name, 'username' => $profile->username],
            ])
            ->all();
    }
}
