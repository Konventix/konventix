<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Competition\Database\Factories\ScoreEntryFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One entrant's score on a leaderboard stage (design §7.2, §8.3).
 *
 * The whole of the leaderboard format is this table: a stage with no matches,
 * where each entrant accumulates values and the standings are a ranking of
 * their aggregate. Four of SXLAN's twenty-seven real tournaments were this
 * shape — a best lap, points per heat, a jury average — and all four were
 * forced into a fake single-elimination bracket because nothing else existed.
 *
 * **An entry does not count until it is verified** (§8.3). `verified_at` null
 * is the normal state of a participant's submission, and
 * `LeaderboardStandingsCalculator` skips those rows rather than ranking them
 * provisionally: a table that moves when somebody types a number and moves
 * back when the desk rejects it is worse than a table that waits.
 * Desk-entered rows are verified on creation, which is not an exception to
 * the rule but the rule applied — the organizer verifying is the same person
 * who submitted.
 *
 * `proof_media_id`, `submitted_by_user_id` and `verified_by_user_id` are bare
 * ULIDs: Platform owns the media library and Identity owns users, and a
 * foreign key across a module boundary is what ADR-0014 forbids.
 *
 * The datetimes are annotated `CarbonInterface`, not `Carbon`:
 * `Date::use(CarbonImmutable::class)` is set app-wide, and
 * `Carbon\CarbonImmutable` does not extend `Illuminate\Support\Carbon`
 * (#312).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $stage_id
 * @property string $entrant_id
 * @property int|null $heat
 * @property float $value
 * @property string|null $proof_media_id
 * @property string $submitted_by_user_id
 * @property CarbonInterface|null $verified_at
 * @property string|null $verified_by_user_id
 * @property CarbonInterface|null $rejected_at
 * @property string|null $rejected_by_user_id
 * @property string|null $rejection_note
 * @property string|null $note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ScoreEntry extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ScoreEntryFactory> */
    use HasFactory, HasUlids;

    protected $table = 'competition_score_entries';

    protected $fillable = [
        'stage_id', 'entrant_id', 'heat', 'value', 'proof_media_id',
        'submitted_by_user_id', 'verified_at', 'verified_by_user_id',
        'rejected_at', 'rejected_by_user_id', 'rejection_note', 'note',
    ];

    /** Whether this entry counts towards the standings (§8.3). */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether an organizer turned this entry down, with a reason (§8.3).
     *
     * Not the opposite of verified — "nobody has looked at it yet" and "an
     * organizer said no" are different things to show a participant, and only
     * the second comes with `rejection_note` to show them.
     */
    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

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
     * `value` is cast to float rather than `decimal:3`, which would hand back
     * a string: a lap time is arithmetic, not money, and the calculator that
     * sums and averages these wants numbers.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'heat' => 'integer',
            'value' => 'float',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ScoreEntryFactory
    {
        return ScoreEntryFactory::new();
    }
}
