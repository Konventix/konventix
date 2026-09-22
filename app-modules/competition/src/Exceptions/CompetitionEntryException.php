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

    public static function teamsAtDifferentEvents(string $fromTeamId, string $toTeamId): self
    {
        return new self(sprintf(
            'Teams [%s] and [%s] belong to different events; a team is per event (D-CMP-14), so there is '
            .'no move between them.',
            $fromTeamId,
            $toTeamId,
        ));
    }

    public static function competitionFull(string $competitionId, int $maxEntrants): self
    {
        return new self(sprintf(
            'Competition [%s] already holds its limit of %d entrants; an incomplete roster holds a slot '
            .'too until it is filled or declined (design §5.4).',
            $competitionId,
            $maxEntrants,
        ));
    }

    public static function emptyRoster(string $teamId): self
    {
        return new self(sprintf('Team [%s] has nobody on it; an empty roster cannot enter anything.', $teamId));
    }

    public static function unknownInviteCode(string $code): self
    {
        return new self(sprintf('No team of this event uses the invite code [%s].', $code));
    }

    public static function alreadyOnTeam(string $userId, string $teamId): self
    {
        return new self(sprintf('User [%s] is already on team [%s].', $userId, $teamId));
    }

    public static function rosterFull(string $teamId, int $teamSize): self
    {
        return new self(sprintf(
            'Team [%s] already fields %d player(s), which is the discipline\'s team size; there are no '
            .'substitutes in this slice (design §5.2).',
            $teamId,
            $teamSize,
        ));
    }

    public static function notOnTeam(string $userId, string $teamId): self
    {
        return new self(sprintf('User [%s] is not on team [%s].', $userId, $teamId));
    }

    public static function notTheCaptain(string $userId, string $teamId): self
    {
        return new self(sprintf('User [%s] does not captain team [%s].', $userId, $teamId));
    }

    public static function entrantNotIncomplete(string $entrantId): self
    {
        return new self(sprintf(
            'Entrant [%s] is not incomplete; only an incomplete roster is filled up or declined (design §5.4).',
            $entrantId,
        ));
    }

    public static function entrantAlreadyLeft(string $entrantId): self
    {
        return new self(sprintf('Entrant [%s] has already withdrawn or been disqualified.', $entrantId));
    }

    public static function competitionOver(string $competitionId): self
    {
        return new self(sprintf(
            'Competition [%s] is finished or cancelled; its field is history and does not change.',
            $competitionId,
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
