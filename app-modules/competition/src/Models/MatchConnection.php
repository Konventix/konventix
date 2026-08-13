<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One edge of the progression graph: "the winner of match A becomes slot 2 of
 * match B".
 *
 * Explicit edges rather than positional arithmetic. Single elimination could
 * compute its next match as `floor((position - 1) / 2) + 1`, but double
 * elimination cannot: a loser drops into a match chosen by a crossover rule
 * that depends on the round, not on its own position. One mechanism that
 * expresses every format beats two that disagree at the edges — and it makes
 * the bracket inspectable, which arithmetic buried in a resolver is not.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $source_match_id
 * @property string $target_match_id
 * @property string $source_outcome
 * @property int $target_slot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MatchConnection extends Model
{
    use BelongsToBrand;
    use HasUlids;

    public const string OUTCOME_WINNER = 'winner';

    public const string OUTCOME_LOSER = 'loser';

    protected $fillable = ['source_match_id', 'target_match_id', 'source_outcome', 'target_slot'];

    /**
     * @return BelongsTo<CompetitionMatch, $this>
     */
    public function sourceMatch(): BelongsTo
    {
        return $this->belongsTo(CompetitionMatch::class, 'source_match_id');
    }

    /**
     * @return BelongsTo<CompetitionMatch, $this>
     */
    public function targetMatch(): BelongsTo
    {
        return $this->belongsTo(CompetitionMatch::class, 'target_match_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['target_slot' => 'integer'];
    }
}
