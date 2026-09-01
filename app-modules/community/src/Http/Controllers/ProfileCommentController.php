<?php

declare(strict_types=1);

namespace Konventix\Community\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Community\Http\Concerns\ResolvesProfiles;
use Konventix\Community\Models\CommunityComment;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Community\Models\CommunityReaction;

/**
 * Writes to a profile wall (Task 9): comments and reactions on them.
 *
 * Every method resolves through `resolveProfileForMemberOrFail` — the same
 * 404 wall `ProfileController::show` renders behind, extended to also
 * require the viewer's own membership (ADR-0052 §2: comments stay
 * internal-scope regardless of the wall's own visibility). The routes are
 * deliberately NOT in an `auth` group: a login redirect would concede the
 * page exists to a guest it must 404 for instead.
 *
 * No moderation or report flow here (ruled) — deletion is author-or-
 * wall-owner only, and a third party gets a 403 rather than a 404, because
 * the page they are acting from already conceded the comment's existence by
 * rendering it (`MyRegistrationsController::show`'s ownership-403
 * precedent).
 */
final class ProfileCommentController
{
    use ResolvesProfiles;

    public function store(Request $request, string $username): RedirectResponse
    {
        ['profile' => $profile] = $this->resolveProfileForMemberOrFail($request, $username);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        CommunityComment::query()->create([
            'module' => CommunityComment::MODULE,
            'entity_type' => CommunityComment::ENTITY_PROFILE,
            'entity_id' => $profile->getKey(),
            'author_user_id' => (string) $request->user()->getAuthIdentifier(),
            'body' => $validated['body'],
        ]);

        return back();
    }

    public function destroy(Request $request, string $username, string $comment): RedirectResponse
    {
        ['profile' => $profile] = $this->resolveProfileForMemberOrFail($request, $username);

        $model = $this->findComment($profile, $comment);

        $viewerId = (string) $request->user()->getAuthIdentifier();
        $isAuthor = $model->author_user_id === $viewerId;
        $isWallOwner = $profile->getKey() === $viewerId;

        abort_unless($isAuthor || $isWallOwner, 403);

        CommunityReaction::query()
            ->forSubject(CommunityComment::MODULE, CommunityReaction::ENTITY_COMMENT, $model->getKey())
            ->delete();

        $model->delete();

        return back();
    }

    public function react(Request $request, string $username, string $comment): RedirectResponse
    {
        ['profile' => $profile] = $this->resolveProfileForMemberOrFail($request, $username);

        $model = $this->findComment($profile, $comment);
        $viewerId = (string) $request->user()->getAuthIdentifier();

        $this->guardNotOwn($model, $viewerId);

        DB::transaction(function () use ($model, $viewerId): void {
            CommunityReaction::query()->firstOrCreate([
                'user_id' => $viewerId,
                'module' => CommunityComment::MODULE,
                'entity_type' => CommunityReaction::ENTITY_COMMENT,
                'entity_id' => $model->getKey(),
            ]);

            $model->forceFill(['reaction_count' => CommunityReaction::countFor($model)])->save();
        });

        return back();
    }

    public function unreact(Request $request, string $username, string $comment): RedirectResponse
    {
        ['profile' => $profile] = $this->resolveProfileForMemberOrFail($request, $username);

        $model = $this->findComment($profile, $comment);
        $viewerId = (string) $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($model, $viewerId): void {
            CommunityReaction::query()
                ->where('user_id', $viewerId)
                ->forSubject(CommunityComment::MODULE, CommunityReaction::ENTITY_COMMENT, $model->getKey())
                ->delete();

            $model->forceFill(['reaction_count' => CommunityReaction::countFor($model)])->save();
        });

        return back();
    }

    /**
     * A comment scoped to THIS profile's wall, or 404 — the same "hidden
     * means it does not exist" answer a comment on someone else's wall (or a
     * stale id) gets.
     */
    private function findComment(CommunityProfile $profile, string $comment): CommunityComment
    {
        /** @var CommunityComment|null $model */
        $model = CommunityComment::query()
            ->forSubject(CommunityComment::MODULE, CommunityComment::ENTITY_PROFILE, $profile->getKey())
            ->whereKey($comment)
            ->first();

        abort_if($model === null, 404);

        return $model;
    }

    private function guardNotOwn(CommunityComment $comment, string $viewerId): void
    {
        if ($comment->author_user_id === $viewerId) {
            throw ValidationException::withMessages(['comment' => __('You cannot react to your own comment.')]);
        }
    }
}
