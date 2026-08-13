<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use RuntimeException;

/**
 * Thrown when a team cannot enter a competition. Named causes because a
 * player told "you cannot sign up" deserves to know whether signup is shut,
 * they are already in, or they hold no confirmed registration.
 */
final class CompetitionEntryException extends RuntimeException
{
    public static function signupClosed(string $competitionId): self
    {
        return new self(sprintf('Signup for competition [%s] is not open.', $competitionId));
    }

    public static function alreadyEntered(string $competitionId, string $teamId): self
    {
        return new self(sprintf('Team [%s] is already entered in competition [%s].', $teamId, $competitionId));
    }

    public static function noConfirmedRegistration(string $userId, string $eventId): self
    {
        return new self(sprintf(
            'User [%s] holds no confirmed registration for event [%s]; a confirmed registration is '
            .'what entitles someone to enter (D-CMP-05).',
            $userId,
            $eventId,
        ));
    }

    public static function wrongEvent(string $competitionId, string $teamId): self
    {
        return new self(sprintf(
            'Team [%s] belongs to a different event than competition [%s]; teams are per event (D-CMP-14).',
            $teamId,
            $competitionId,
        ));
    }
}
