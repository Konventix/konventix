<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\GuardsSideMembership;
use Konventix\Competition\Actions\Concerns\NotifiesRosterMembers;
use Konventix\Competition\Actions\Concerns\SettlesAMatch;
use Konventix\Competition\Data\EntrantNoticeData;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Data\MatchReportData;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Events\MatchResultReported;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Proofs\ProofGate;

/**
 * A side of a match says what the result was (design §8.2, step 1 and 2).
 *
 * The whole of §8.2's flow lives in one Action because it is one decision
 * from the participant's side — "this is what happened" — and what follows
 * depends entirely on what the *other* side has already said:
 *
 * - **Nobody else has reported.** The claim is filed, the match gains
 *   `reported_at`, and the other side is notified. Nothing is recorded.
 * - **The other side reported the same thing.** That is agreement, however
 *   it was expressed, and `ConfirmMatchResult`'s outcome follows: the result
 *   is recorded, `MatchFinished` fires once, the bracket advances and the
 *   standings move. §8.2 draws confirming and counter-reporting as two
 *   branches, but a counter-report that agrees *is* a confirmation, and
 *   refusing to treat it as one would leave a match stuck because both sides
 *   pressed the wrong button.
 * - **The other side reported something else.** The match is disputed, both
 *   reports stay, and the desk decides (§8.4).
 *
 * The same holds while the match is **already disputed**: a claim that now
 * agrees with the other side's settles the match and closes the dispute, and
 * one that still disagrees replaces the side's claim and leaves the one open
 * dispute where it is (final review, part 1, I5).
 *
 * A side that reports twice has changed its mind rather than filed a second
 * claim, so the row is replaced — the unique index says the same thing.
 *
 * `desk_only` skips all of this (§8.1, §8.2 step 3): the desk records through
 * `RecordMatchResult` directly, and a participant is refused here rather than
 * only hidden from on the page. A report on a match that already has a
 * recorded result is refused outright (§8.2, step 4) — from there only a
 * correction can change anything. So is a report on a match whose other side
 * has not arrived yet (`guardEverySideHasArrived`), which is the desk's rule
 * too.
 */
final class ReportMatchResult
{
    use GuardsCompetitionLifecycle;
    use GuardsSideMembership;
    use NotifiesRosterMembers;
    use SettlesAMatch;

    public function __construct(
        private readonly RecordMatchHistory $history,
        private readonly ProofGate $proofs,
        private readonly DisputeMatchResult $disputes,
    ) {}

    public function handle(
        CompetitionMatch $match,
        Entrant $entrant,
        MatchReportData $data,
        string $reportedByUserId,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $entrant, $data, $reportedByUserId): CompetitionMatch {
            // First, before anything is written: a competition that is over
            // takes no report — before this, the first claim on a leftover
            // match of a finished or cancelled tournament was filed and the
            // other side asked to confirm it (final review, part 4, I2) — and
            // the match is re-read under the lock, which also serialises two
            // sides reporting at the same moment (part 1, I3).
            $this->lockMatchForPlay($match);

            $competition = $this->competitionOf($match);

            $this->guardParticipantsMayReport($competition);
            $this->guardMatchIsOpenForReporting($match);
            $this->guardIsASideOf($match, $entrant);
            $this->guardIsOnTheRoster($entrant, $reportedByUserId);

            // A side still to come — an empty slot fed by an undecided match —
            // has nobody who could confirm or contradict this claim, and a
            // claim filed against nobody would sit on the match as the only
            // report once the opponent arrives. The desk refuses the same
            // match for the same reason (`RecordResultAtDesk`); a bye, which
            // nothing will ever fill, passes.
            $sides = $this->entrantIdsByPosition($match);
            $this->guardEverySideHasArrived($match, $sides);

            $scores = $data->normalisedScores();
            $this->guardScoreLineIsComplete($match, $scores, $sides);

            // A claim that names no winner — a tie, or no scores at all — is
            // a draw, and a draw is refused here, when it is filed, wherever
            // the bracket cannot carry one: filing it would ask the other
            // side to confirm a result that could never be recorded.
            $this->guardDrawIsPossible($match, $this->winnerFromScores($match, $scores));

            $proofMediaId = $this->proofs->resolve($competition, $match->brand_id, $data->proofMediaId);

            $report = $this->fileReport($match, $entrant, $scores, $proofMediaId, $reportedByUserId);

            $this->proofs->recordUsage($proofMediaId, $match->brand_id, 'match_result_report', $report->id);

            if ($match->reported_at === null) {
                $match->forceFill(['reported_at' => now()])->save();
                $match->refresh();
            }

            $this->history->handle(
                $match,
                MatchHistoryKind::Reported,
                new MatchHistoryPayload(
                    entrantId: $entrant->id,
                    scores: $scores,
                    note: $data->note,
                    proofMediaId: $proofMediaId,
                ),
                $reportedByUserId,
            );

            // After the commit: the settle or dispute branch below can still
            // roll the report back, and a report that was never filed must
            // not have been announced (.ai/rules/src.md).
            $reported = new MatchResultReported(
                matchId: $match->id,
                competitionId: $match->competition_id,
                brandId: $match->brand_id,
                entrantId: $entrant->id,
                userId: $reportedByUserId,
                at: now()->toIso8601String(),
            );

            DB::afterCommit(static function () use ($reported): void {
                event($reported);
            });

            $opposing = $this->opposingReport($match, $entrant);

            if ($opposing === null) {
                $this->tellTheOtherSide($competition, $match, $entrant);

                return $match->refresh();
            }

            if ($report->agreesWith($opposing)) {
                $this->history->handle(
                    $match,
                    MatchHistoryKind::Confirmed,
                    new MatchHistoryPayload(entrantId: $entrant->id, scores: $scores),
                    $reportedByUserId,
                );

                return $this->settleMatch(
                    $match,
                    $scores,
                    $this->winnerFromScores($match, $scores),
                    $reportedByUserId,
                );
            }

            // Still disagreeing on a match that is already disputed: the side
            // changed its claim, which is filed above with its history row,
            // and the dispute the desk already has stays the one dispute.
            // Before the final review this was refused as "already disputed"
            // and the side's edit thrown away (T5 M-2, part 1, I5).
            if ($match->isDisputed()) {
                return $match->refresh();
            }

            // The dispute's reason lands in the match history, which both
            // sides read on the match page, so it is German (ADR-0001).
            return $this->disputes->handle(
                $match,
                $entrant,
                __('Die beiden Seiten haben unterschiedliche Ergebnisse gemeldet.'),
                $reportedByUserId,
            );
        });
    }

    /**
     * @param  array<int, int|null>  $scores
     */
    private function fileReport(
        CompetitionMatch $match,
        Entrant $entrant,
        array $scores,
        ?string $proofMediaId,
        string $reportedByUserId,
    ): MatchResultReport {
        /** @var MatchResultReport|null $report */
        $report = MatchResultReport::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->where('entrant_id', $entrant->id)
            ->first();

        $report ??= new MatchResultReport;

        $report->fill([
            'competition_match_id' => $match->id,
            'entrant_id' => $entrant->id,
            'reported_by_user_id' => $reportedByUserId,
            'scores' => $scores,
            'proof_media_id' => $proofMediaId,
        ]);

        // brand_id from the match, not ambient context (ADR-0010).
        $report->forceFill(['brand_id' => $match->brand_id])->save();

        return $report->refresh();
    }

    /**
     * The other side's claim, if it has filed one.
     *
     * `first()` rather than `sole()` on purpose: `match_slots.position`
     * already allows more than two sides (§7.4), and while no generator
     * produces one today, a three-sided match should not fatal here — the
     * first contradicting claim is enough to make it a dispute, which is the
     * only thing this asks.
     */
    private function opposingReport(CompetitionMatch $match, Entrant $entrant): ?MatchResultReport
    {
        // A side of the match *now*: a claim left by a team a correction
        // moved out of the slot is no one's to agree or disagree with
        // (final review, part 4, I1).
        /** @var MatchResultReport|null $report */
        $report = MatchResultReport::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->where('entrant_id', '!=', $entrant->id)
            ->whereIn('entrant_id', array_values($this->entrantIdsByPosition($match)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $report;
    }

    /**
     * "…the other side is notified" (§8.2, step 1).
     *
     * Every roster member of every *other* side, not just its captain: the
     * person who can confirm is whoever is at their keyboard. A failed send
     * never fails the report — `NotifiesRosterMembers` explains the trade.
     */
    private function tellTheOtherSide(Competition $competition, CompetitionMatch $match, Entrant $entrant): void
    {
        $recipients = [];

        foreach ($this->entrantIdsByPosition($match) as $entrantId) {
            if ($entrantId === $entrant->id) {
                continue;
            }

            /** @var Entrant|null $side */
            $side = Entrant::withoutBrandScope()->whereKey($entrantId)->first();

            $recipients = [...$recipients, ...($side?->memberUserIds() ?? [])];
        }

        /** @var CompetitionTeam|null $team */
        $team = CompetitionTeam::withoutBrandScope()->whereKey($entrant->competition_team_id)->first();

        $this->notifyRoster(
            $recipients,
            $match->brand_id,
            'competition.result-reported',
            new EntrantNoticeData(
                competitionName: $competition->name,
                teamName: $team->name ?? '',
                detail: __('Das gegnerische Team hat ein Ergebnis gemeldet. Bestätige es oder melde dein eigenes.'),
            ),
        );
    }
}
