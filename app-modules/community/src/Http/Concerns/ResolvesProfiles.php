<?php

declare(strict_types=1);

namespace Konventix\Community\Http\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Konventix\Community\Models\CommunityEventParticipation;
use Konventix\Community\Models\CommunityMember;
use Konventix\Community\Models\CommunityProfile;
use Konventix\Identity\Enums\ProfileVisibility;

/**
 * The whole visibility gate in one place (ADR-0052, superseding ADR-0051
 * §7's original members-only-everywhere matrix): a subject must have a
 * profile AND a `CommunityMember` row on THIS brand under every
 * visibility — whether a person is a member of a brand is itself personal
 * data, so no answer may differ on that question alone. `internal` (the
 * default) additionally requires a signed-in viewer with their own
 * `CommunityMember` row on this brand; `event` additionally requires a
 * shared CONFIRMED event participation on this brand; `public` has no
 * viewer checks at all, guests included.
 *
 * Every denial is a 404 — never 403, never a login redirect, because the
 * route is deliberately NOT in an auth group (ADR-0051 §7).
 *
 * Comments stay internal-scope under every visibility (ADR-0052 §2): the
 * `viewerIsMember` flag returned here is exactly the `internal` check, so a
 * caller (this task's controller, Task 9's comments) can gate
 * comment-eligibility on it without re-deriving the same logic.
 */
trait ResolvesProfiles
{
    /**
     * @return array{profile: CommunityProfile, member: CommunityMember, viewerIsMember: bool}
     */
    protected function resolveProfileOrFail(Request $request, string $username): array
    {
        $profile = CommunityProfile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->first();

        abort_if($profile === null, 404);

        $member = CommunityMember::query()->where('user_id', $profile->getKey())->first();

        abort_if($member === null, 404);

        $viewer = $request->user();

        $viewerIsMember = $viewer !== null
            && CommunityMember::query()->where('user_id', (string) $viewer->getAuthIdentifier())->exists();

        match (ProfileVisibility::from($profile->visibility)) {
            ProfileVisibility::Public => null,
            ProfileVisibility::Internal => abort_unless($viewerIsMember, 404),
            ProfileVisibility::Event => $this->assertSharesConfirmedEvent($viewer, $viewerIsMember, $profile),
        };

        return ['profile' => $profile, 'member' => $member, 'viewerIsMember' => $viewerIsMember];
    }

    /**
     * `event` visibility is `internal`'s checks plus a shared CONFIRMED
     * event participation on this brand (ADR-0052 §3) — sourced from
     * Community's own `community_event_participations` read model, never
     * from Registration's tables (ADR-0004).
     */
    private function assertSharesConfirmedEvent(?Authenticatable $viewer, bool $viewerIsMember, CommunityProfile $profile): void
    {
        abort_unless($viewerIsMember, 404);

        /** @var Authenticatable $viewer */
        $viewerEventIds = CommunityEventParticipation::query()
            ->where('user_id', (string) $viewer->getAuthIdentifier())
            ->where('confirmed', true)
            ->pluck('event_id');

        $shares = $viewerEventIds->isNotEmpty() && CommunityEventParticipation::query()
            ->where('user_id', $profile->getKey())
            ->where('confirmed', true)
            ->whereIn('event_id', $viewerEventIds)
            ->exists();

        abort_unless($shares, 404);
    }
}
