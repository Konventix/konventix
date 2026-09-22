<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One person on a team. `user_id` is a plain ULID — Identity owns users
 * (ADR-0004, ADR-0014) — and the name that goes beside it on a page comes
 * from `ParticipantProfile`, never from Identity's model.
 *
 * `role` is `captain` or `member` (design §5.2), exactly one captain per
 * team. The uniqueness of that is kept by the roster Actions rather than by
 * a partial index: captaincy rotates inside the same transaction that removes
 * the departing captain, so a constraint would only ever fire on a bug the
 * Actions already refuse.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_team_id
 * @property string $user_id
 * @property TeamRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompetitionTeamMember extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = ['competition_team_id', 'user_id', 'role'];

    public function isCaptain(): bool
    {
        return $this->role === TeamRole::Captain;
    }

    /**
     * @return BelongsTo<CompetitionTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(CompetitionTeam::class, 'competition_team_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => TeamRole::class];
    }
}
