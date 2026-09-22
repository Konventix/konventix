<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * It survives a void as well (design §8.5): a match a correction took the
 * result away from is played *again*, and that replay owes GameOps no second
 * teardown either. `RecordMatchResult` reads it, `CorrectMatchResult` leaves
 * it alone, and nothing clears it.
 *
 * `reported_at`, `disputed_at` and `dispute_resolved_at` are §8.2's and
 * §8.4's marks on the match itself: a side has claimed a result, the sides
 * disagree, the desk decided. The dispute is a *pair* rather than one
 * nullable flag because "there was a dispute here" is worth keeping after it
 * is settled — and §13's `competition_disputes_open` metric is exactly the
 * matches with the first set and the second not.
 *
 * The datetimes are annotated `CarbonInterface`, not `Carbon`:
 * `Date::use(CarbonImmutable::class)` is set app-wide and
 * `Carbon\CarbonImmutable` does not extend `Illuminate\Support\Carbon`
 * (#312).
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
 * @property CarbonInterface|null $scheduled_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $finished_event_emitted_at
 * @property CarbonInterface|null $reported_at
 * @property CarbonInterface|null $disputed_at
 * @property CarbonInterface|null $dispute_resolved_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
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
        'reported_at', 'disputed_at', 'dispute_resolved_at',
    ];

    /**
     * @return BelongsTo<Competition, $this>
     */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    /** Whether the sides disagree and the desk has not yet decided (§8.4). */
    public function isDisputed(): bool
    {
        return $this->disputed_at !== null && $this->dispute_resolved_at === null;
    }

    /**
     * @return HasMany<MatchSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(MatchSlot::class)->orderBy('position');
    }

    /**
     * This match's timeline, oldest first (§8.6).
     *
     * @return HasMany<MatchHistoryEntry, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(MatchHistoryEntry::class)->orderBy('created_at');
    }

    /**
     * The claims the sides filed (§8.2).
     *
     * @return HasMany<MatchResultReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(MatchResultReport::class)->orderBy('created_at');
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
            'reported_at' => 'datetime',
            'disputed_at' => 'datetime',
            'dispute_resolved_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CompetitionMatchFactory
    {
        return CompetitionMatchFactory::new();
    }
}
