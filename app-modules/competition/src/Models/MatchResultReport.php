<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * What one side of a match says the result was (design §8.2).
 *
 * A claim, not a result. Two agreeing claims make a result and the match is
 * recorded; two disagreeing claims make a dispute and **both rows stay**, so
 * the desk resolving it can see what each side actually said rather than
 * whichever one was written last.
 *
 * `scores` is keyed by **slot position**. A correction re-routes entrants
 * through the progression graph (§8.5), so the entrant behind slot 2 can
 * change over the life of a match while the slot cannot; positions also make
 * "do these two reports agree?" a plain comparison of two maps with the same
 * keys. The entrant-keyed form `RecordMatchResult` wants is resolved from the
 * match's slots at the moment of recording.
 *
 * `reported_by_user_id` and `proof_media_id` are bare ULIDs: Identity owns
 * users and Platform owns the media library (ADR-0014, ADR-0035).
 *
 * The datetimes are annotated `CarbonInterface`, not `Carbon`:
 * `Date::use(CarbonImmutable::class)` is app-wide and `CarbonImmutable` does
 * not extend `Illuminate\Support\Carbon` (#312).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_match_id
 * @property string $entrant_id
 * @property string|null $reported_by_user_id null once the reporter was erased (GDPR)
 * @property array<int|string, int|null> $scores
 * @property string|null $proof_media_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class MatchResultReport extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = [
        'competition_match_id', 'entrant_id', 'reported_by_user_id', 'scores', 'proof_media_id',
    ];

    /**
     * The claim as `position => score`, positions as integers and in
     * ascending order.
     *
     * A json column hands its keys back as strings, and two reports that
     * disagree only about the type of their keys agree about the result.
     * Normalising here, once, is what lets {@see agreesWith()} be `===`.
     *
     * @return array<int, int|null>
     */
    public function normalisedScores(): array
    {
        $scores = [];

        foreach ($this->scores as $position => $score) {
            $scores[(int) $position] = $score === null ? null : (int) $score;
        }

        ksort($scores);

        return $scores;
    }

    /**
     * Whether the two sides said the same thing (§8.2): identical scores for
     * identical positions.
     */
    public function agreesWith(self $other): bool
    {
        return $this->normalisedScores() === $other->normalisedScores();
    }

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
        return ['scores' => 'array'];
    }
}
