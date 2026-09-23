<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\GuardsSideMembership;
use Konventix\Competition\Actions\Concerns\SettlesAMatch;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;

/**
 * The desk records a head-to-head result itself (design §8.1, §8.2 step 3,
 * §9): "`desk_only` mode skips all of this: the desk records directly" — and
 * in `participants` mode "the desk can always record".
 *
 * It ends where an agreed report ends, so it settles the match the same way
 * (`SettlesAMatch`): the result is recorded, `MatchFinished` fires once, the
 * bracket advances and the standings are recomputed. A desk that recorded a
 * semi-final and then had to find the final's slot empty would be the bug.
 *
 * It will not finish a match against nobody: every slot must be filled, or
 * be a **bye** that nothing will ever fill (`guardEverySideHasArrived`) — a
 * round-two match whose other side is still being played waits. Nor will it
 * name a winner who is not a side or record a draw where the bracket cannot
 * advance one; `settleMatch()` refuses both (`GuardsMatchOutcome`).
 *
 * Two matches it will not take, and each has its own door:
 *
 * - a **disputed** one — the two sides are owed a reason, so that is
 *   `ResolveDispute` with its mandatory note (§8.4);
 * - a **finished** one — that is a correction, `CorrectMatchResult`, which is
 *   `CorrectResults` rather than `RecordResults` (§11). `RecordMatchResult`
 *   refuses it by state already.
 *
 * Scores are keyed by slot position like every other claim in §8, and the
 * winner is named rather than derived: the desk may award a match on
 * something the score line does not show (a forfeit, a no-show), and `null`
 * is a draw.
 */
final class RecordResultAtDesk
{
    use GuardsCompetitionLifecycle;
    use GuardsSideMembership;
    use SettlesAMatch;

    /**
     * @param  array<int, int|null>  $scoresByPosition  slot position => score
     */
    public function handle(
        CompetitionMatch $match,
        array $scoresByPosition,
        ?string $winnerEntrantId,
        string $recordedByUserId,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $scoresByPosition, $winnerEntrantId, $recordedByUserId): CompetitionMatch {
            // Re-read under the lock: a dispute raised a moment ago is seen.
            $this->lockMatchForPlay($match);

            if ($match->isDisputed()) {
                throw ResultSubmissionException::resolveInstead($match->id);
            }

            $sides = $this->entrantIdsByPosition($match);

            $this->guardEverySideHasArrived($match, $sides);
            $this->guardScoreLineIsComplete($match, $scoresByPosition, $sides);

            // The winner is a side, and a draw only where the bracket carries
            // one: `settleMatch()` checks both for every settle path.
            return $this->settleMatch($match, $scoresByPosition, $winnerEntrantId, $recordedByUserId);
        });
    }
}
