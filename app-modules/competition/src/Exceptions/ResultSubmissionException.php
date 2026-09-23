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
 * **Two audiences, two languages** (ADR-0001), as in
 * `CompetitionEntryException`. The message is English, for the desk, the log
 * and the tests. Every cause a participant can reach carries a German
 * sentence as well (`participantMessage()`), which names no ULID and no
 * design section. The two causes only the desk reaches fall back to a generic
 * German sentence.
 */
final class ResultSubmissionException extends RuntimeException
{
    /**
     * The German sentence for a participant, or null for a console-only
     * cause (`notDisputed`, `resolveInstead`).
     */
    private ?string $participantMessage = null;

    /**
     * What a participant is told (ADR-0001): the cause in German, or a
     * generic German sentence for a cause only the desk should reach.
     */
    public function participantMessage(): string
    {
        return $this->participantMessage
            ?? __('That did not work. Reload the page and try again.');
    }

    /**
     * A refusal a participant can reach: the English message for the console
     * and the log, the German one for the participant's page.
     */
    private static function refusal(string $message, string $participantMessage): self
    {
        $exception = new self($message);
        $exception->participantMessage = $participantMessage;

        return $exception;
    }

    /**
     * §8.1's first knob. The desk can always record, whatever this says; what
     * `desk_only` shuts is the participant's door, and it is shut in the
     * Action rather than only on the form — a client that ignores the
     * interface must still be refused.
     */
    public static function deskOnly(string $competitionId): self
    {
        return self::refusal(
            sprintf(
                'Competition [%s] is set to `desk_only`: the organizers record results, participants do not '
                .'submit them (design §8.1).',
                $competitionId,
            ),
            __('In this tournament, the organizers enter the results.'),
        );
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
        return self::refusal(
            sprintf(
                'Competition [%s] has a proof policy of `none`: it takes no proof images, so there is nothing '
                .'to upload (design §8.1, §8.7).',
                $competitionId,
            ),
            __('This tournament does not take proof images.'),
        );
    }

    /**
     * The upload door once the competition is `finished` or `cancelled`
     * (final review, part 2, M3): no result can be reported or submitted any
     * more, so an image could only ever be an orphan on the disk.
     */
    public static function takesNoMoreProofs(string $competitionId, string $status): self
    {
        return self::refusal(
            sprintf(
                'Competition [%s] is `%s`: it takes no more results, so it takes no proof images either.',
                $competitionId,
                $status,
            ),
            __('This tournament is over and takes no more proof images.'),
        );
    }

    public static function notASide(string $matchId, string $entrantId): self
    {
        return self::refusal(
            sprintf(
                'Entrant [%s] is not a side of match [%s]; only the teams playing a match say what happened in it.',
                $entrantId,
                $matchId,
            ),
            __('Your team is not playing in this match.'),
        );
    }

    public static function notOnRoster(string $userId, string $entrantId): self
    {
        return self::refusal(
            sprintf(
                'User [%s] is not on the roster behind entrant [%s]; reporting for a side is a membership '
                .'right, not a capability (design §11).',
                $userId,
                $entrantId,
            ),
            __('You are not on the team for this side.'),
        );
    }

    public static function alreadyRecorded(string $matchId): self
    {
        return self::refusal(
            sprintf(
                'Match [%s] already has a recorded result; from here only a correction by the desk can change '
                .'it (design §8.2).',
                $matchId,
            ),
            __('The result of this match is already settled. Only the organizers can change it now.'),
        );
    }

    public static function matchNotPlayable(string $matchId, string $state): self
    {
        return self::refusal(
            sprintf(
                'Match [%s] is `%s`; there is no result to report on it.',
                $matchId,
                $state,
            ),
            __('No result can be reported for this match right now.'),
        );
    }

    public static function incompleteScoreLine(string $matchId): self
    {
        return self::refusal(
            sprintf(
                'A report on match [%s] has to give a score for every side of it; a partial score line cannot '
                .'be compared with the other side\'s.',
                $matchId,
            ),
            __('Please enter a result for both sides.'),
        );
    }

    public static function nothingToConfirm(string $matchId): self
    {
        return self::refusal(
            sprintf(
                'Nobody has reported a result for match [%s] yet, so there is nothing to confirm.',
                $matchId,
            ),
            __('There is no result for you to confirm yet.'),
        );
    }

    public static function cannotConfirmOwnReport(string $matchId, string $entrantId): self
    {
        return self::refusal(
            sprintf(
                'Entrant [%s] filed the only report on match [%s]; a result is agreed by the *other* side '
                .'(design §8.2).',
                $entrantId,
                $matchId,
            ),
            __('Your own result is confirmed by the other side, not by you.'),
        );
    }

    public static function notDisputed(string $matchId): self
    {
        return new self(sprintf(
            'Match [%s] is not disputed; there is nothing to resolve.',
            $matchId,
        ));
    }

    /**
     * The desk pressed "record" on a match the two sides disagree about. A
     * dispute is settled with a note both sides are shown (§8.4), which a
     * plain recording would skip.
     */
    public static function resolveInstead(string $matchId): self
    {
        return new self(sprintf(
            'Match [%s] is disputed; resolve the dispute with a note instead of recording over it (design §8.4).',
            $matchId,
        ));
    }

    /**
     * A side of the match is still to come: its slot is fed by a match that
     * has not been decided. Recording now would finish a match against
     * nobody and advance a side past an opponent who has not arrived. A
     * **bye** — an empty slot nothing will ever fill — is not this.
     */
    public static function sideStillToCome(string $matchId): self
    {
        return self::refusal(
            sprintf(
                'Match [%s] is still waiting for a side; record it once both teams are known.',
                $matchId,
            ),
            __('The opponent is not known yet: the match before this one is not decided.'),
        );
    }

    /**
     * A draw where the format or the discipline has none (§7.1, ADR-0062):
     * an elimination bracket cannot send "nobody" on, and a discipline
     * without draws has a winner in every match.
     */
    public static function drawNotPossible(string $matchId, string $format): self
    {
        return self::refusal(
            sprintf(
                'Match [%s] cannot end in a draw: its stage runs `%s`, where every match needs a winner.',
                $matchId,
                $format,
            ),
            __('This tournament has no draws. A match needs a winner.'),
        );
    }

    /**
     * A score entry on a stage that plays matches (§7.2): only a leaderboard
     * accumulates scores.
     */
    public static function notALeaderboard(string $stageId, string $format): self
    {
        return self::refusal(
            sprintf(
                'Stage [%s] runs [%s]; only a leaderboard stage accumulates score entries.',
                $stageId,
                $format,
            ),
            __('This tournament does not enter results into a leaderboard.'),
        );
    }

    public static function notInTheField(string $entrantId, string $stageId): self
    {
        return self::refusal(
            sprintf(
                'Entrant [%s] does not belong to stage [%s]\'s competition.',
                $entrantId,
                $stageId,
            ),
            __('Your team is not playing in this tournament.'),
        );
    }

    public static function noLongerScores(string $entrantId, string $state): self
    {
        return self::refusal(
            sprintf(
                'Entrant [%s] is [%s] and no longer scores.',
                $entrantId,
                $state,
            ),
            __('Your team is no longer in the tournament and cannot submit results.'),
        );
    }

    public static function alreadyDisputed(string $matchId): self
    {
        return self::refusal(
            sprintf(
                'Match [%s] is already disputed and waiting for the desk.',
                $matchId,
            ),
            __('This result is already disputed. The organizers decide.'),
        );
    }

    /**
     * §8.4 on a competition that is over. Only the dispute path asks today:
     * a dispute is the one thing a side may raise after a result exists, so
     * without this it would have no end at all.
     */
    public static function competitionOver(string $competitionId, string $status): self
    {
        return self::refusal(
            sprintf(
                'Competition [%s] is `%s`; its results can no longer be disputed.',
                $competitionId,
                $status,
            ),
            __('This tournament is over. Its results can no longer be disputed.'),
        );
    }
}
