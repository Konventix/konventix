<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use Konventix\Competition\Enums\CompetitionStatus;
use RuntimeException;

/**
 * A desk operation the competition's lifecycle does not allow right now
 * (design §4, D-CMP-15).
 *
 * The transition table says where a competition may *go*;
 * `IllegalCompetitionStateTransitionException` refuses a move. This refuses
 * the work that happens *inside* a state: drawing a bracket while signup can
 * still add entrants, emptying the bracket of a competition that is being
 * played, or writing a result into one that is over. Named causes, so the
 * desk is told which of the three it ran into.
 *
 * The messages are English and written for the desk, which reads them as
 * they are. A participant can reach one cause too — `over()`, when a confirm
 * or a leaderboard score arrives from a page opened before the competition
 * finished or was called off — and never sees this text:
 * `Http\Controllers\Concerns\AnswersTheParticipant` answers it with a fixed
 * German sentence instead (ADR-0001).
 */
final class CompetitionLifecycleException extends RuntimeException
{
    /**
     * §4: the bracket is drawn and seeded in `SignupClosed` — before it,
     * signup may still add an entrant the draw would leave out — and redrawn
     * in `Running` only until the first result (D-CMP-20).
     */
    public static function drawNotOpen(string $competitionId, CompetitionStatus $status): self
    {
        return new self(sprintf(
            'Competition [%s] is `%s`; the bracket is seeded and drawn once signup is closed, and redrawn '
            .'only until the first result (design §4, §7.5). Close signup first.',
            $competitionId,
            $status->value,
        ));
    }

    /**
     * §4: `Running` **requires** a generated stage. A reset would leave a
     * running competition with nothing to play; "draw again" replaces the
     * bracket in one step and is the button for that case.
     */
    public static function wouldEmptyARunningCompetition(string $competitionId): self
    {
        return new self(sprintf(
            'Competition [%s] is running, and a running competition needs its bracket (design §4). '
            .'Draw again instead of resetting.',
            $competitionId,
        ));
    }

    /**
     * `Finished` and `Cancelled` are history (§4): nothing is scheduled,
     * recorded, corrected, resolved or verified in them any more.
     */
    public static function over(string $competitionId, CompetitionStatus $status): self
    {
        return new self(sprintf(
            'Competition [%s] is `%s`; its matches and scores can no longer be scheduled or changed.',
            $competitionId,
            $status->value,
        ));
    }
}
