<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One entrant's row in a stage's standings.
 *
 * A projection of finished matches, recomputed wholesale by
 * `CalculateStandings` — never written a row at a time as results come in.
 * Incremental updates are how a standings table drifts from the matches it
 * claims to summarise, and a full recompute of a stage is cheap.
 *
 * `placement` is shared between tied entrants (standard competition ranking),
 * so it is deliberately not unique per stage.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string $stage_id
 * @property string $entrant_id
 * @property string|null $group
 * @property int $placement
 * @property int $wins
 * @property int $losses
 * @property int $draws
 * @property int $points
 * @property float $tiebreaker
 * @property Carbon $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Standing extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'competition_standings';

    protected $fillable = [
        'competition_id', 'stage_id', 'entrant_id', 'group', 'placement',
        'wins', 'losses', 'draws', 'points', 'tiebreaker', 'computed_at',
    ];

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    /**
     * @return BelongsTo<Entrant, $this>
     */
    public function entrant(): BelongsTo
    {
        return $this->belongsTo(Entrant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement' => 'integer',
            'wins' => 'integer',
            'losses' => 'integer',
            'draws' => 'integer',
            'points' => 'integer',
            'tiebreaker' => 'float',
            'computed_at' => 'datetime',
        ];
    }
}
