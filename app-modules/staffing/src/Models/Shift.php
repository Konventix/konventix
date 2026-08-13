<?php

declare(strict_types=1);

namespace Konventix\Staffing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Staffing\Database\Factories\ShiftFactory;

/**
 * A time-bounded piece of work under a team.
 *
 * Uses the same half-open `[starts_at, ends_at)` convention as Resources, but
 * with **no exclusion constraint**: ADR-0020 states outright that "Agenda and
 * Staffing do not book". Two shifts may overlap, and a person working both is
 * a rostering judgement for a lead, not an error for a database.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $team_id
 * @property string $event_id
 * @property string $title
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Shift extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ShiftFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['team_id', 'event_id', 'title', 'starts_at', 'ends_at', 'published_at'];

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<ShiftRole, $this>
     */
    public function shiftRoles(): HasMany
    {
        return $this->hasMany(ShiftRole::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ShiftFactory
    {
        return ShiftFactory::new();
    }
}
