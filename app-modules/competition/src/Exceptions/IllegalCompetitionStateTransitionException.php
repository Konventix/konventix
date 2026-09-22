<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use Konventix\Competition\Enums\CompetitionStatus;
use RuntimeException;

/**
 * An illegal move on the competition lifecycle (D-CMP-15, design §4).
 *
 * Mirrors `IllegalMatchStateTransitionException` so both state machines read
 * the same way: `CompetitionStatus::allowedTransitions()` is the authority,
 * every lifecycle Action consults it, and this is what it throws.
 */
final class IllegalCompetitionStateTransitionException extends RuntimeException
{
    public static function forTransition(
        string $competitionId,
        CompetitionStatus $from,
        CompetitionStatus $to,
    ): self {
        return new self(sprintf(
            'Competition [%s] cannot transition from [%s] to [%s].',
            $competitionId,
            $from->value,
            $to->value,
        ));
    }

    /**
     * A transition that is legal on the table but refused by a precondition
     * — `start` with no stage generated, or while a roster is incomplete
     * (design §4, §5.4).
     */
    public static function forPrecondition(string $competitionId, string $reason): self
    {
        return new self(sprintf('Competition [%s] cannot start: %s', $competitionId, $reason));
    }
}
