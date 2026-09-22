<?php

declare(strict_types=1);

namespace Konventix\Competition\Exceptions;

use RuntimeException;

/**
 * Thrown when a result cannot be submitted, confirmed, disputed or resolved
 * (design §8.1, §8.2, §8.4).
 *
 * Both shapes of participant-submitted result live here: a head-to-head
 * report (§8.2) and a leaderboard score entry (§8.3). §8.1's
 * `result_submission_mode` governs both — "whether participants may report"
 * — so its refusal has to read naturally for both, which is why the class is
 * named after the submission rather than after the match.
 *
 * Named causes, for the same reason `CompetitionEntryException` has them: a
 * player told "you cannot report this" deserves to know whether the desk
 * keeps results to itself, whether they are on the wrong team, or whether the
 * match was settled while they were typing.
 *
 * The messages are English, matching the module's existing refusal copy.
 * Participant-reachable causes across this module move to German in one pass
 * (ADR-0001, §11's slice); starting a second convention here would make that
 * pass harder, not easier.
 */
final class ResultSubmissionException extends RuntimeException
{
    /**
     * §8.1's first knob. The desk can always record, whatever this says; what
     * `desk_only` shuts is the participant's door, and it is shut in the
     * Action rather than only on the form — a client that ignores the
     * interface must still be refused.
     */
    public static function deskOnly(string $competitionId): self
    {
        return new self(sprintf(
            'Competition [%s] is set to `desk_only`: the organizers record results, participants do not '
            .'submit them (design §8.1).',
            $competitionId,
        ));
    }

    /**
     * §8.7's upload door, refusing before a byte is stored. The guards in
     * `SubmitScoreEntry` and `ReportMatchResult` say the same thing, but they
     * say it after the participant has already uploaded the image: a
     * `proof_policy` of `none` that only refused at submission time would
     * leave an orphaned object on the disk and reject the whole score over an
     * attachment nobody asked for.
     */
    public static function takesNoProofs(string $competitionId): self
    {
        return new self(sprintf(
            'Competition [%s] has a proof policy of `none`: it takes no proof images, so there is nothing '
            .'to upload (design §8.1, §8.7).',
            $competitionId,
        ));
    }

    public static function notASide(string $matchId, string $entrantId): self
    {
        return new self(sprintf(
            'Entrant [%s] is not a side of match [%s]; only the teams playing a match say what happened in it.',
            $entrantId,
            $matchId,
        ));
    }

    public static function notOnRoster(string $userId, string $entrantId): self
    {
        return new self(sprintf(
            'User [%s] is not on the roster behind entrant [%s]; reporting for a side is a membership '
            .'right, not a capability (design §11).',
            $userId,
            $entrantId,
        ));
    }

    public static function alreadyRecorded(string $matchId): self
    {
        return new self(sprintf(
            'Match [%s] already has a recorded result; from here only a correction by the desk can change '
            .'it (design §8.2).',
            $matchId,
        ));
    }

    public static function matchNotPlayable(string $matchId, string $state): self
    {
        return new self(sprintf(
            'Match [%s] is `%s`; there is no result to report on it.',
            $matchId,
            $state,
        ));
    }

    public static function incompleteScoreLine(string $matchId): self
    {
        return new self(sprintf(
            'A report on match [%s] has to give a score for every side of it; a partial score line cannot '
            .'be compared with the other side\'s.',
            $matchId,
        ));
    }

    public static function nothingToConfirm(string $matchId): self
    {
        return new self(sprintf(
            'Nobody has reported a result for match [%s] yet, so there is nothing to confirm.',
            $matchId,
        ));
    }

    public static function cannotConfirmOwnReport(string $matchId, string $entrantId): self
    {
        return new self(sprintf(
            'Entrant [%s] filed the only report on match [%s]; a result is agreed by the *other* side '
            .'(design §8.2).',
            $entrantId,
            $matchId,
        ));
    }

    public static function notDisputed(string $matchId): self
    {
        return new self(sprintf(
            'Match [%s] is not disputed; there is nothing to resolve.',
            $matchId,
        ));
    }

    public static function alreadyDisputed(string $matchId): self
    {
        return new self(sprintf(
            'Match [%s] is already disputed and waiting for the desk.',
            $matchId,
        ));
    }
}
