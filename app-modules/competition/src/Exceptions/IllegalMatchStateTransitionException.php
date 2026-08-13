<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use RuntimeException;

/**
 * An illegal move on the match state machine. Mirrors Registration's
 * `IllegalRegistrationStateTransitionException` so both read the same way.
 */
final class IllegalMatchStateTransitionException extends RuntimeException
{
    public static function forTransition(CompetitionMatch $match, MatchState $target): self
    {
        return new self(sprintf(
            'Match [%s] cannot transition from [%s] to [%s].',
            $match->id,
            $match->state->value,
            $target->value,
        ));
    }

    public static function forAction(CompetitionMatch $match, string $action): self
    {
        return new self(sprintf(
            'Match [%s] cannot be %s while in state [%s].',
            $match->id,
            $action,
            $match->state->value,
        ));
    }
}
