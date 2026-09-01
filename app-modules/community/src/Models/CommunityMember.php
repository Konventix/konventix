<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Community's per-brand membership read model: the participant's
 * brand-facing face (avatar, banner) and "member since" timestamp,
 * projected from Identity's `BrandMembershipProfileUpdated` by
 * `Listeners\ProjectCommunityMember` — idempotent, replayable, and
 * rebuildable from a replay of that event.
 *
 * Brand-owned (ADR-0010): `BelongsToBrand` + `HasUlids`. `user_id` names
 * Identity's account — a plain ULID column, no FK (ADR-0014). No factory:
 * rows exist only through the projection, so tests emit the Identity event
 * instead of inventing a membership.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $user_id
 * @property string|null $avatar_url
 * @property string|null $banner_url
 * @property Carbon $member_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommunityMember extends Model
{
    use BelongsToBrand, HasUlids;

    protected $table = 'community_members';

    // `brand_id` is deliberately NOT fillable — it is never mass-assigned.
    // `BelongsToBrand`'s invariant (.ai/rules/models.md) is that the only
    // way to set it is the ambient-context `creating` hook or the named
    // `withoutBrandScope()` escape hatch; a mass-assignable `brand_id` would
    // let any caller's array override it. The projection listener writes the
    // event's brand via `forceFill()` instead — see
    // `Listeners\ProjectCommunityMember` for why.
    protected $fillable = ['user_id', 'avatar_url', 'banner_url', 'member_since'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'member_since' => 'datetime',
        ];
    }
}
