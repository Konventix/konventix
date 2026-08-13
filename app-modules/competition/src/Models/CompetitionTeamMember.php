<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One person on a team. `user_id` is a plain ULID — Identity owns users
 * (ADR-0004, ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_team_id
 * @property string $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompetitionTeamMember extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = ['competition_team_id', 'user_id'];

    /**
     * @return BelongsTo<CompetitionTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(CompetitionTeam::class, 'competition_team_id');
    }
}
