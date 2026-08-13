<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One side of a match, and its score.
 *
 * A row per side rather than two columns on the match: a format with more
 * than two sides — a race heat, a free-for-all — then needs no schema change,
 * and the progression graph ADR-0019 absorbs already thinks in slots.
 *
 * `entrant_id` is nullable because a slot exists before it is filled: that is
 * what a bracket *is* before its earlier rounds resolve.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_match_id
 * @property string|null $entrant_id
 * @property int $position
 * @property int|null $score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MatchSlot extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = ['competition_match_id', 'entrant_id', 'position', 'score'];

    /**
     * @return BelongsTo<CompetitionMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(CompetitionMatch::class, 'competition_match_id');
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
        return ['position' => 'integer', 'score' => 'integer'];
    }
}
