<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use RuntimeException;

/**
 * Who may say something about a match (design §8.2, §11).
 *
 * §11 is explicit that the participant actions — enter, join, report,
 * confirm, check in — are **not permissions**: "they derive from membership
 * in a side, captaincy, and the §5.1 gate". So there is no capability to
 * check here and no policy to consult; there is a roster, and either the
 * person is on it or they are not. Any member of a side may report and
 * confirm, not only the captain: a 5v5 whose captain went to get food should
 * not be unable to report the match it just played.
 *
 * The guards are ordered so that the refusal a person sees is the most
 * specific true one: the competition's mode first (nobody may report at all),
 * then the match (settled, cancelled), then the side, then the roster.
 */
trait GuardsSideMembership
{
    /**
     * The competition behind a match. A match without one is corrupt data
     * rather than a state worth handling.
     */
    protected function competitionOf(CompetitionMatch $match): Competition
    {
        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($match->competition_id)->first();

        if ($competition === null) {
            throw new RuntimeException(sprintf(
                'Match [%s] belongs to competition [%s], which no longer exists.',
                $match->id,
                $match->competition_id,
            ));
        }

        return $competition;
    }

    /**
     * §8.1: `desk_only` skips all of this — the desk records directly.
     */
    protected function guardParticipantsMayReport(Competition $competition): void
    {
        if ($competition->result_submission_mode !== ResultSubmissionMode::Participants) {
            throw ResultSubmissionException::deskOnly($competition->id);
        }
    }

    /**
     * §8.2, point 4: "A report on a match that already has a recorded result
     * is refused." A cancelled match is refused for the neighbouring reason —
     * it was not played.
     */
    protected function guardMatchIsOpenForReporting(CompetitionMatch $match): void
    {
        if ($match->state === MatchState::Finished) {
            throw ResultSubmissionException::alreadyRecorded($match->id);
        }

        if ($match->state === MatchState::Cancelled) {
            throw ResultSubmissionException::matchNotPlayable($match->id, $match->state->value);
        }
    }

    /**
     * A dispute is the one thing a side may still raise after a result is
     * recorded (§8.4).
     *
     * §8.4 gives the desk two capabilities for resolving one — "`RecordResults`
     * before a result exists, `CorrectResults` after" — and the second only
     * means anything if a recorded result can be contested. So this refuses a
     * *cancelled* match and nothing else: a match that was never played has
     * no result to argue about, while one that was played and recorded is
     * exactly the case §8.4's second capability is for.
     */
    protected function guardMatchIsDisputable(CompetitionMatch $match): void
    {
        if ($match->state === MatchState::Cancelled) {
            throw ResultSubmissionException::matchNotPlayable($match->id, $match->state->value);
        }
    }

    /**
     * The slot this entrant occupies in the match, refusing an entrant that
     * occupies none.
     */
    protected function guardIsASideOf(CompetitionMatch $match, Entrant $entrant): int
    {
        /** @var MatchSlot|null $slot */
        $slot = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->where('entrant_id', $entrant->id)
            ->first();

        if ($slot === null) {
            throw ResultSubmissionException::notASide($match->id, $entrant->id);
        }

        return $slot->position;
    }

    /**
     * Membership in the side, which is what a participant's right to speak
     * for it consists of (§11).
     */
    protected function guardIsOnTheRoster(Entrant $entrant, string $userId): void
    {
        $onIt = CompetitionTeamMember::withoutBrandScope()
            ->where('brand_id', $entrant->brand_id)
            ->where('competition_team_id', $entrant->competition_team_id)
            ->where('user_id', $userId)
            ->exists();

        if (! $onIt) {
            throw ResultSubmissionException::notOnRoster($userId, $entrant->id);
        }
    }

    /**
     * A claim has to cover every side of the match, or it cannot be compared
     * with the other side's claim at all — and half a score line recorded as
     * a result would hand somebody a walkover.
     *
     * @param  array<int, int|null>  $scoresByPosition
     * @param  array<int, string>  $entrantsByPosition
     */
    protected function guardScoreLineIsComplete(
        CompetitionMatch $match,
        array $scoresByPosition,
        array $entrantsByPosition,
    ): void {
        foreach (array_keys($entrantsByPosition) as $position) {
            if (! array_key_exists($position, $scoresByPosition)) {
                throw ResultSubmissionException::incompleteScoreLine($match->id);
            }
        }

        foreach (array_keys($scoresByPosition) as $position) {
            if (! isset($entrantsByPosition[$position])) {
                throw ResultSubmissionException::incompleteScoreLine($match->id);
            }
        }
    }
}
