<?php

declare(strict_types=1);

namespace Konventix\Competition\Pages;

use Konventix\Competition\Data\BracketMatchData;
use Konventix\Competition\Data\BracketSideData;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchResultReport;

/**
 * What a member of one side may do on a match right now (design §8.2, §8.4):
 * report, confirm, dispute. Two readers ask this: the match page's buttons
 * (`TournamentPages::matchViewer`) and the hub's nudge (`Hub\NextGamesCard`,
 * §10a). One rule for both, so the card never says "Ergebnis melden" on a
 * match whose page offers no form.
 *
 * The Actions refuse anything this lets through; this is the interface's
 * reading of the same rules, not a second guard.
 */
final readonly class MatchPowers
{
    public function __construct(
        public bool $canReport,
        public bool $canConfirm,
        public bool $canDispute,
        /** Every side is there: no slot waits on an undecided match, and it is not a bye. */
        public bool $everySide,
    ) {}

    /**
     * @param  MatchResultReport|null  $own  the viewer's side's report
     * @param  MatchResultReport|null  $opposing  another side's report
     */
    public static function of(
        Competition $competition,
        CompetitionMatch $match,
        BracketMatchData $data,
        ?MatchResultReport $own,
        ?MatchResultReport $opposing,
    ): self {
        $participants = $competition->result_submission_mode === ResultSubmissionMode::Participants;
        $competitionOver = in_array($competition->status, [CompetitionStatus::Finished, CompetitionStatus::Cancelled], true);
        $open = in_array($match->state, [MatchState::Pending, MatchState::Scheduled, MatchState::Live], true)
            && ! $competitionOver;
        // Every side is there: no slot waits on an undecided match. A bye has
        // nobody to report against, so it is the desk's to settle.
        $everySide = count(array_filter($data->sides, fn (BracketSideData $slot): bool => $slot->entrantId !== null)) >= 2
            && ! collect($data->sides)->contains(fn (BracketSideData $slot): bool => $slot->awaiting);

        return new self(
            // Carry ruling: no upload control while proofs are readable by
            // ULID (#322). Where a proof is required, a report without one
            // would only be refused, so the page says the desk collects it.
            canReport: $participants && $open && $everySide && ! $match->isDisputed()
                && $competition->proof_policy !== ProofPolicy::Required,
            canConfirm: $participants && $open && ! $match->isDisputed() && $opposing !== null && $own === null,
            // A dispute is the one thing a side may still raise once a result
            // stands (§8.4) — against the other side's claim, or against the
            // recorded result. Never a second one while the desk has the first.
            canDispute: ! $match->isDisputed()
                && $match->state !== MatchState::Cancelled
                && ! $competitionOver
                && ($opposing !== null || $match->state === MatchState::Finished),
            everySide: $everySide,
        );
    }
}
