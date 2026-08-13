<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\EntrantFactory;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A team entered in a competition. Always a team, even for a solo player
 * (D-CMP-13) — one shape means the bracket engine never branches on whether
 * an entrant is a person or a squad.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string $competition_team_id
 * @property EntrantState $state
 * @property int|null $seed
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Entrant extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntrantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['competition_id', 'competition_team_id', 'state', 'seed', 'checked_in_at'];

    /** Whether this entrant still occupies a place in the competition. */
    public function isActive(): bool
    {
        return in_array($this->state, [EntrantState::Registered, EntrantState::CheckedIn], true);
    }

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
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
        return ['state' => EntrantState::class, 'seed' => 'integer', 'checked_in_at' => 'datetime'];
    }

    protected static function newFactory(): EntrantFactory
    {
        return EntrantFactory::new();
    }
}
