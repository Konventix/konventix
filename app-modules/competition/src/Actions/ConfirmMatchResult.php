<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\GuardsSideMembership;
use Konventix\Competition\Actions\Concerns\SettlesAMatch;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchResultReport;

/**
 * The other side agrees with what was reported (design §8.2, step 2).
 *
 * "Confirmed → `RecordMatchResult` runs with the agreed scores. The match is
 * `finished`, `MatchFinished` fires once (D-CMP-12), the bracket advances.
 * **From here only `CorrectResults` can change it.**"
 *
 * Confirming takes no scores. That is the point of it: the confirming side is
 * saying "what they wrote is right", and a form that let them re-type the
 * numbers would turn every agreement into a chance to disagree by typo. A
 * side that does *not* agree reports its own result instead, and
 * `ReportMatchResult` turns the contradiction into a dispute.
 *
 * Two refusals worth naming apart: there is nothing to confirm when nobody
 * has reported, and a side cannot confirm **its own** claim — a result is
 * agreed by the other side or it is not agreed.
 */
final class ConfirmMatchResult
{
    use GuardsCompetitionLifecycle;
    use GuardsSideMembership;
    use SettlesAMatch;

    public function __construct(
        private readonly RecordMatchHistory $history,
    ) {}

    public function handle(
        CompetitionMatch $match,
        Entrant $entrant,
        string $confirmedByUserId,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $entrant, $confirmedByUserId): CompetitionMatch {
            // A competition that is over takes no result, and the match is
            // re-read under the lock: of two members of a side pressing
            // "confirm", the second finds it finished and is refused here
            // (D-CMP-12; final review, part 1, I3).
            $this->lockMatchForPlay($match);

            $competition = $this->competitionOf($match);

            $this->guardParticipantsMayReport($competition);
            $this->guardMatchIsOpenForReporting($match);
            $this->guardIsASideOf($match, $entrant);
            $this->guardIsOnTheRoster($entrant, $confirmedByUserId);

            $reports = MatchResultReport::withoutBrandScope()
                ->where('brand_id', $match->brand_id)
                ->where('competition_match_id', $match->id)
                ->orderBy('created_at')
                ->get();

            if ($reports->isEmpty()) {
                throw ResultSubmissionException::nothingToConfirm($match->id);
            }

            /** @var MatchResultReport|null $theirs */
            $theirs = $reports->first(fn (MatchResultReport $report): bool => $report->entrant_id !== $entrant->id);

            if ($theirs === null) {
                throw ResultSubmissionException::cannotConfirmOwnReport($match->id, $entrant->id);
            }

            $scores = $theirs->normalisedScores();

            $this->history->handle(
                $match,
                MatchHistoryKind::Confirmed,
                new MatchHistoryPayload(entrantId: $entrant->id, scores: $scores),
                $confirmedByUserId,
            );

            return $this->settleMatch(
                $match,
                $scores,
                $this->winnerFromScores($match, $scores),
                $confirmedByUserId,
            );
        });
    }
}
