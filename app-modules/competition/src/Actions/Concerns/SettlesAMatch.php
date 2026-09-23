<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Konventix\Competition\Actions\AdvanceBracket;
use Konventix\Competition\Actions\ForfeitRetiredEntrants;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;

/**
 * The three things that always happen together once a head-to-head result is
 * agreed (design §8.2): the result is recorded, the bracket advances, and the
 * standings are recomputed.
 *
 * Shared by `ReportMatchResult` (when the second side's report agrees with
 * the first), `ConfirmMatchResult`, `RecordResultAtDesk` and `ResolveDispute`,
 * because all four end in the same place by different routes and a copy each
 * would be four chances to forget the recompute. A forfeit is not settled
 * here: `Concerns\ForfeitsOpenMatches` records and advances each one itself
 * (for `DisqualifyEntrant`, and for `ForfeitRetiredEntrants`, which every
 * settle calls), because a forfeit against an opponent who is not there yet
 * is not a settle at all, and it recomputes every stage once at the end.
 *
 * It also holds the **position ↔ entrant** translation. A report is keyed by
 * slot position (§8.2) because a correction can re-route who stands in a slot
 * (§8.5); `RecordMatchResult` is keyed by entrant because that is what
 * `MatchFinished` carries to GameOps. One place does the conversion.
 */
trait SettlesAMatch
{
    use ClosesADispute;
    use GuardsMatchOutcome;
    use RecomputesStageStandings;

    /**
     * Record an agreed result, advance the bracket, recompute the standings.
     *
     * The winner is checked here, on every settle path, rather than by each
     * caller (`GuardsMatchOutcome`): it has to be a side of the match, and a
     * null winner — a tie, or two empty scores — is refused wherever the
     * bracket cannot carry a draw. Before the final review only the desk's
     * two paths asked, and two sides agreeing on 1:1 finished an elimination
     * match with nobody to send on (final review, part 1, I1).
     *
     * A match that is still disputed when it is settled here was settled by
     * the sides agreeing after all — a confirm, or a re-report that now
     * matches the other claim — so the dispute is closed with it
     * (`ClosesADispute`; part 1, I5). `ResolveDispute` closes its own before
     * it gets here.
     *
     * @param  array<int, int|null>  $scoresByPosition
     */
    protected function settleMatch(
        CompetitionMatch $match,
        array $scoresByPosition,
        ?string $winnerEntrantId,
        ?string $recordedByUserId = null,
    ): CompetitionMatch {
        $this->guardWinnerIsASide($match, $winnerEntrantId, $this->entrantIdsByPosition($match));
        $this->guardDrawIsPossible($match, $winnerEntrantId);

        $this->closeDispute($match, $winnerEntrantId, $recordedByUserId, __('The two sides agreed on a result after all.'));

        $settled = app(RecordMatchResult::class)->handle(
            $match,
            $this->scoresByEntrant($match, $scoresByPosition),
            $winnerEntrantId,
            $recordedByUserId,
        );

        app(AdvanceBracket::class)->handle($settled);

        // This may be the competition's first result, the moment a redraw
        // stops being possible: a team that withdrew before it is taken out
        // of the bracket now (fix wave A round 3, M-f).
        app(ForfeitRetiredEntrants::class)->handle($settled->competition_id, $settled->brand_id);

        $this->recomputeStandings($settled);

        return $settled->refresh();
    }

    /**
     * The match's sides, `position => entrant ULID`, unfilled slots left out.
     *
     * @return array<int, string>
     */
    protected function entrantIdsByPosition(CompetitionMatch $match): array
    {
        $byPosition = [];

        $slots = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->orderBy('position')
            ->get();

        foreach ($slots as $slot) {
            if ($slot->entrant_id !== null) {
                $byPosition[$slot->position] = $slot->entrant_id;
            }
        }

        return $byPosition;
    }

    /**
     * A position-keyed claim, translated into what `RecordMatchResult` takes.
     * A score for a position nobody occupies is dropped rather than written
     * against a null entrant.
     *
     * @param  array<int, int|null>  $scoresByPosition
     * @return array<string, int|null>
     */
    protected function scoresByEntrant(CompetitionMatch $match, array $scoresByPosition): array
    {
        $entrants = $this->entrantIdsByPosition($match);
        $scores = [];

        foreach ($scoresByPosition as $position => $score) {
            if (isset($entrants[$position])) {
                $scores[$entrants[$position]] = $score;
            }
        }

        return $scores;
    }

    /**
     * Who won, given a position-keyed claim: the single highest score.
     *
     * A tie is **null**, not a coin toss — a drawn match is a real outcome for
     * a discipline that allows draws (`disciplines.allow_draws`), and for one
     * that does not it is a result the desk has to look at rather than one
     * this code should invent a winner for. A missing score counts as no
     * score, which is what a bye already looks like.
     *
     * @param  array<int, int|null>  $scoresByPosition
     */
    protected function winnerFromScores(CompetitionMatch $match, array $scoresByPosition): ?string
    {
        $entrants = $this->entrantIdsByPosition($match);
        $best = null;
        $leaders = [];

        foreach ($scoresByPosition as $position => $score) {
            if ($score === null || ! isset($entrants[$position])) {
                continue;
            }

            if ($best === null || $score > $best) {
                $best = $score;
                $leaders = [$entrants[$position]];

                continue;
            }

            if ($score === $best) {
                $leaders[] = $entrants[$position];
            }
        }

        return count($leaders) === 1 ? $leaders[0] : null;
    }

    /**
     * Refuses to settle a match one of whose sides is **still to come**: an
     * empty slot fed by an edge whose source match is not finished (§7.4 —
     * the progression graph says who fills it). An empty slot with no such
     * edge is a **bye**, which is settled by giving the lone side the match,
     * and stays allowed; so does a slot whose feeding match is over and left
     * it empty (a double-elimination loser slot fed by a bye).
     *
     * @param  array<int, string>  $sides  position => entrant ULID, filled slots only
     */
    protected function guardEverySideHasArrived(CompetitionMatch $match, array $sides): void
    {
        if ($sides === [] || $this->awaitsASide($match, $sides)) {
            throw ResultSubmissionException::sideStillToCome($match->id);
        }
    }

    /**
     * Whether an empty slot of this match is fed by a match that is not
     * finished yet — somebody is still on the way into it.
     *
     * @param  array<int, string>  $sides  position => entrant ULID, filled slots only
     */
    protected function awaitsASide(CompetitionMatch $match, array $sides): bool
    {
        return MatchConnection::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('target_match_id', $match->id)
            ->whereNotIn('target_slot', $sides === [] ? [0] : array_keys($sides))
            ->whereIn('source_match_id', CompetitionMatch::withoutBrandScope()
                ->select('id')
                ->where('brand_id', $match->brand_id)
                ->where('state', '!=', MatchState::Finished->value))
            ->exists();
    }
}
