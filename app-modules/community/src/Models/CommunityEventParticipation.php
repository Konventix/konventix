<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Community's read model of "who is confirmed for what event" (ADR-0052
 * §3), the source `event`-scoped profile visibility resolves against —
 * Community may not read Registration's models (ADR-0004), only its
 * events, via `Listeners\ProjectEventParticipation`.
 *
 * PK is the REGISTRATION id carried in the event payload, not generated —
 * same non-generated-PK shape as `CommunityProfile` (no `HasUlids`).
 * Brand-owned (ADR-0010): `BelongsToBrand`. `user_id`/`event_id` name
 * Identity's account and Registration's event — plain ULID columns, no FK
 * (ADR-0014). No factory: rows exist only through the projection, tests
 * emit the Registration event instead of inventing a row.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $user_id
 * @property string $event_id
 * @property bool $confirmed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommunityEventParticipation extends Model
{
    use BelongsToBrand;

    protected $table = 'community_event_participations';

    public $incrementing = false;

    protected $keyType = 'string';

    // `brand_id` is deliberately NOT fillable — it is never mass-assigned.
    // `BelongsToBrand`'s invariant (.ai/rules/models.md) is that the only
    // way to set it is the ambient-context `creating` hook or the named
    // `withoutBrandScope()` escape hatch; a mass-assignable `brand_id`
    // would let any caller's array override it. The projection listener
    // writes the event's brand via `forceFill()` instead — see
    // `Listeners\ProjectEventParticipation` for why (mirrors
    // `CommunityMember`).
    protected $fillable = ['id', 'user_id', 'event_id', 'confirmed'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed' => 'boolean',
        ];
    }
}
