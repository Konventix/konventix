<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\GuardsSideMembership;
use Konventix\Competition\Actions\Concerns\SettlesAMatch;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\MatchDisputeResolved;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;

/**
 * The desk decides a disputed match (design §8.4).
 *
 * "`ResolveDispute` records the result the organizer decides on and
 * **requires a `resolution_note`**, which lands in the match history (§8.6)
 * and is shown to both sides."
 *
 * The note is the whole point, and it is mandatory in the Action rather than
 * on the form: two teams who disagreed about a match are owed a reason, and a
 * blank one would make the timeline say only that somebody in a lanyard
 * decided. `RecordMatchHistory` refuses a `resolved` row without one too, so
 * there is no route past it.
 *
 * ## Recording or correcting
 *
 * §8.4 gives the capability as "`RecordResults` before a result exists,
 * `CorrectResults` after", and the Action follows the same fork:
 *
 * - The match is **not** finished — the usual case, the sides disagreed
 *   before anything was recorded — so the decided result is *recorded*:
 *   `MatchFinished` fires once, the bracket advances, the standings move.
 * - The match **is** finished, because the dispute was raised after a result
 *   was recorded. Then this is a *correction*: `MatchResultCorrected` is
 *   emitted, no second `MatchFinished` goes out (D-CMP-12), and the cascade
 *   of §8.5 runs with the resolution note as the note that voided rows
 *   carry.
 *
 * The capability itself is checked at the route by the console slice that
 * owns the screen (ADR-0015 leaves the gate on the operation; §9's slice
 * wires it).
 *
 * The winner is given explicitly rather than derived from the scores. A desk
 * resolving a dispute may award a match on something the score line does not
 * say — a forfeit, a cheat, a disconnect — and `null` is a legitimate answer
 * for a drawn or voided match.
 */
final class ResolveDispute
{
    use GuardsCompetitionLifecycle;
    use GuardsSideMembership;
    use SettlesAMatch;

    public function __construct(
        private readonly RecordMatchHistory $history,
        private readonly CorrectMatchResult $corrections,
    ) {}

    /**
     * @param  array<int, int|null>  $scoresByPosition  slot position => score
     */
    public function handle(
        CompetitionMatch $match,
        array $scoresByPosition,
        ?string $winnerEntrantId,
        string $resolvedByUserId,
        string $resolutionNote,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $scoresByPosition, $winnerEntrantId, $resolvedByUserId, $resolutionNote): CompetitionMatch {
            $this->guardStillBeingPlayed($match->competition_id);

            if ($match->disputed_at === null || $match->dispute_resolved_at !== null) {
                throw ResultSubmissionException::notDisputed($match->id);
            }

            if (trim($resolutionNote) === '') {
                throw ValidationException::withMessages([
                    'resolutionNote' => __('A resolution needs a note; both sides are shown why you decided as you did.'),
                ]);
            }

            $sides = $this->entrantIdsByPosition($match);

            // Both branches below check the winner again (`settleMatch()`,
            // `CorrectMatchResult`); asking here refuses before anything is
            // stamped, and names the field the desk got wrong.
            $this->guardScoreLineIsComplete($match, $scoresByPosition, $sides);
            $this->guardWinnerIsASide($match, $winnerEntrantId, $sides);
            $this->guardDrawIsPossible($match, $winnerEntrantId);

            $match->forceFill(['dispute_resolved_at' => now()])->save();
            $match->refresh();

            $this->history->handle(
                $match,
                MatchHistoryKind::Resolved,
                new MatchHistoryPayload(
                    scores: $scoresByPosition,
                    winnerEntrantId: $winnerEntrantId,
                    note: $resolutionNote,
                ),
                $resolvedByUserId,
            );

            $resolved = new MatchDisputeResolved(
                matchId: $match->id,
                competitionId: $match->competition_id,
                brandId: $match->brand_id,
                entrantId: $winnerEntrantId,
                userId: $resolvedByUserId,
                at: now()->toIso8601String(),
            );

            DB::afterCommit(static function () use ($resolved): void {
                event($resolved);
            });

            if ($match->state === MatchState::Finished) {
                return $this->corrections->handle(
                    $match,
                    $this->scoresByEntrant($match, $scoresByPosition),
                    $winnerEntrantId,
                    $resolvedByUserId,
                    $resolutionNote,
                );
            }

            return $this->settleMatch($match, $scoresByPosition, $winnerEntrantId, $resolvedByUserId);
        });
    }
}
