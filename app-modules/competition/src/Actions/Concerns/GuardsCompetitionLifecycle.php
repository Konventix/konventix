<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Exceptions\CompetitionLifecycleException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;

/**
 * What a competition's lifecycle state allows the desk to do inside it
 * (design §4, D-CMP-15, D-CMP-20).
 *
 * `TransitionsCompetitionStatus` is the one writer of the state; this is its
 * reading counterpart for every Action that works *within* a state. It locks
 * the competition row the same way the writer does, so a draw and a
 * concurrent "start" or "cancel" are serialised instead of each reading the
 * state the other is about to leave — the lock is held until the caller's
 * transaction ends, which is why every caller runs inside one.
 *
 * Three rules, straight from §4's table:
 *
 * - **The draw** (seed, generate, redraw) happens in `SignupClosed` — "the
 *   bracket can be generated and seeded" — and in `Running` until the first
 *   result, which `RefusesOnceAResultIsRecorded` guards. Not in `Draft` or
 *   `Published`: signup may still add an entrant the bracket would leave out.
 * - **Emptying the draw** (reset) happens in `SignupClosed` only: `Running`
 *   requires a generated stage.
 * - **Playing** (schedule, record, correct, resolve, verify, reject, score)
 *   stops at `Finished` and `Cancelled`, which are history.
 *
 * ## The match is read again under the lock
 *
 * The competition lock serialises two writers, but it does not refresh the
 * `CompetitionMatch` a controller loaded before the transaction began. Two
 * members of a side pressing "confirm", or two desk tabs recording the same
 * match, both loaded it as `pending`; the second waited on the lock and then
 * passed every check on the stale copy, recorded again and sent a second
 * `MatchFinished` (D-CMP-12; final review, part 1, I3). So every Action that
 * writes a match takes the competition lock first — the one order every
 * writer uses, so two of them cannot deadlock — and then re-reads the match
 * `FOR UPDATE` into the instance it was handed (`lockMatchForPlay()`, the
 * idiom `CheckInEntrant` uses for the entrant).
 */
trait GuardsCompetitionLifecycle
{
    private function lockedCompetition(string $competitionId): Competition
    {
        /** @var Competition */
        return Competition::withoutBrandScope()->whereKey($competitionId)->lockForUpdate()->firstOrFail();
    }

    private function guardDrawIsOpen(string $competitionId): Competition
    {
        $competition = $this->lockedCompetition($competitionId);

        if (! in_array($competition->status, [CompetitionStatus::SignupClosed, CompetitionStatus::Running], true)) {
            throw CompetitionLifecycleException::drawNotOpen($competition->id, $competition->status);
        }

        return $competition;
    }

    private function guardDrawMayBeEmptied(string $competitionId): void
    {
        $competition = $this->guardDrawIsOpen($competitionId);

        if ($competition->status === CompetitionStatus::Running) {
            throw CompetitionLifecycleException::wouldEmptyARunningCompetition($competition->id);
        }
    }

    private function guardStillBeingPlayed(string $competitionId): void
    {
        $competition = $this->lockedCompetition($competitionId);

        if ($competition->status->isTerminal()) {
            throw CompetitionLifecycleException::over($competition->id, $competition->status);
        }
    }

    /**
     * Refuses a competition that is over, then re-reads the match under a
     * row lock into the instance the caller holds, so every check after this
     * line sees the match as it is now and not as the request found it.
     */
    private function lockMatchForPlay(CompetitionMatch $match): void
    {
        $this->guardStillBeingPlayed($match->competition_id);
        $this->rereadMatchUnderLock($match);
    }

    /**
     * The same lock order and re-read, for a caller that answers a finished
     * competition with a refusal of its own (a dispute, §8.4).
     */
    private function lockMatch(CompetitionMatch $match): void
    {
        $this->lockedCompetition($match->competition_id);
        $this->rereadMatchUnderLock($match);
    }

    private function rereadMatchUnderLock(CompetitionMatch $match): void
    {
        $locked = CompetitionMatch::withoutBrandScope()->whereKey($match->id)->lockForUpdate()->firstOrFail();

        $match->setRawAttributes($locked->getAttributes(), true);
    }
}
