<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\CompetitionTeamFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A team, per event (D-CMP-14). Cross-event identity stays Community
 * territory; an event's `predecessor_event_id` is what a later roster-import
 * UX would lean on rather than a team spanning years.
 *
 * A solo player is a team of size one (D-CMP-13) — one shape, so the bracket
 * engine never has to ask whether an entrant is a person or a squad.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompetitionTeam extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CompetitionTeamFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['event_id', 'name'];

    /**
     * @return HasMany<CompetitionTeamMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(CompetitionTeamMember::class);
    }

    protected static function newFactory(): CompetitionTeamFactory
    {
        return CompetitionTeamFactory::new();
    }
}
