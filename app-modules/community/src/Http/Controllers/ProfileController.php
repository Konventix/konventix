<?php

declare(strict_types=1);

namespace Konventix\Community\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Community\Http\Concerns\ResolvesProfiles;

/**
 * `GET /u/{username}` — Community's first routed page, the brand-hosted
 * account page ADR-0051 §7 promised and ADR-0052 gave a visibility gate to.
 *
 * The full gate lives in `ResolvesProfiles` (shared with Task 9's comments
 * surface, which reuses `viewerIsMember` to decide comment-eligibility —
 * ADR-0052 §2, comments stay internal-scope regardless of the page's own
 * visibility).
 */
final class ProfileController
{
    use ResolvesProfiles;

    public function show(Request $request, string $username): Response
    {
        ['profile' => $profile, 'member' => $member, 'viewerIsMember' => $viewerIsMember] = $this->resolveProfileOrFail($request, $username);

        $viewerId = $request->user()?->getAuthIdentifier();

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
                'isSelf' => $viewerId !== null && $profile->getKey() === (string) $viewerId,
            ],
            'viewerIsMember' => $viewerIsMember,
        ]);
    }
}
