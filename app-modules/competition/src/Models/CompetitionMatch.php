<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\CompetitionMatchFactory;
use Konventix\Competition\Enums\MatchState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One match. Named `CompetitionMatch` because `Match` is a reserved word in
 * PHP 8 (`match` expressions), which is also why the table is
 * `competition_matches`.
 *
 * `finished_event_emitted_at` is the guard behind D-CMP-12's "MatchFinished
 * fires exactly once per match": a correction rewrites the score and
 * re-derives standings without re-emitting, so GameOps — which tears a
 * deployment down on `MatchFinished` — never tears the same one down twice.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_id
 * @property string|null $stage_id
 * @property int $round
 * @property string|null $bracket
 * @property int $position
 * @property MatchState $state
 * @property string|null $winner_entrant_id
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $finished_event_emitted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CompetitionMatch extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CompetitionMatchFactory> */
    use HasFactory, HasUlids;

    protected $table = 'competition_matches';

    protected $fillable = [
        'competition_id', 'stage_id', 'round', 'position', 'bracket', 'state',
        'winner_entrant_id', 'scheduled_at', 'finished_at', 'finished_event_emitted_at',
    ];

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /**
     * @return HasMany<MatchSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(MatchSlot::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => MatchState::class,
            'round' => 'integer',
            'position' => 'integer',
            'scheduled_at' => 'datetime',
            'finished_at' => 'datetime',
            'finished_event_emitted_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CompetitionMatchFactory
    {
        return CompetitionMatchFactory::new();
    }
}
