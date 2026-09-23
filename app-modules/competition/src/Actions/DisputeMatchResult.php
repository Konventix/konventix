<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Actions\Concerns\GuardsSideMembership;
use Konventix\Competition\Actions\Concerns\NotifiesRosterMembers;
use Konventix\Competition\Data\EntrantNoticeData;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Events\MatchDisputed;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;

/**
 * A side says the reported result is wrong (design §8.2, §8.4).
 *
 * Two ways in, one state. `ReportMatchResult` calls this when a second claim
 * contradicts the first — "Different → the match is **disputed**
 * (`disputed_at`), both reports stay, the desk is notified" — and a side can
 * also call it directly to contest without filing a counter-claim of its own,
 * which is what a team that simply cannot agree on a number needs.
 *
 * **The reports are not touched.** Both stay exactly as filed: §8.4 has an
 * organizer resolve this, and an organizer who could not see what each side
 * actually claimed would be deciding blind.
 *
 * `disputed_at` and `dispute_resolved_at` are kept as a pair rather than one
 * nullable flag, because "there was a dispute here" is worth keeping after it
 * is settled — the match history reads better for it, and §13's
 * `competition_disputes_open` metric and its thirty-minute alert are exactly
 * the matches with the first set and the second not.
 *
 * A reason is mandatory. A dispute with no reason is a stalled match and an
 * organizer with nothing to go on. And a `desk_only` competition takes none:
 * there the sides submit nothing (§8.1), so an objection is made at the desk.
 *
 * **A recorded result can be disputed too.** `ReportMatchResult` refuses a
 * *report* on a finished match (§8.2, point 4) — from there only the desk
 * changes the score — but §8.4 gives the desk two capabilities for resolving a
 * dispute, "`RecordResults` before a result exists, `CorrectResults` after",
 * and the second only means anything if a side can contest a result that was
 * already recorded. `ResolveDispute` then resolves it as a correction, with
 * the cascade of §8.5 and no second `MatchFinished`.
 */
final class DisputeMatchResult
{
    use GuardsCompetitionLifecycle;
    use GuardsSideMembership;
    use NotifiesRosterMembers;

    public function __construct(
        private readonly RecordMatchHistory $history,
    ) {}

    public function handle(
        CompetitionMatch $match,
        Entrant $entrant,
        string $reason,
        string $disputedByUserId,
    ): CompetitionMatch {
        return DB::transaction(function () use ($match, $entrant, $reason, $disputedByUserId): CompetitionMatch {
            // Re-read under the lock, so two disputes at the same moment are
            // one dispute and one "already disputed". Not `lockMatchForPlay()`:
            // a finished competition gets its own refusal below.
            $this->lockMatch($match);

            // §8.1: in `desk_only` the sides do not submit anything, a
            // dispute included — the desk records, and an objection goes to
            // the desk in person. The same refusal a report gets.
            $this->guardParticipantsMayReport($this->competitionOf($match));
            $this->guardMatchIsDisputable($match);
            $this->guardIsASideOf($match, $entrant);
            $this->guardIsOnTheRoster($entrant, $disputedByUserId);

            if ($match->disputed_at !== null && $match->dispute_resolved_at === null) {
                throw ResultSubmissionException::alreadyDisputed($match->id);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => __('Schreib bitte dazu, was an dem gemeldeten Ergebnis nicht stimmt.'),
                ]);
            }

            $match->forceFill([
                'disputed_at' => now(),
                // A second dispute on a match the desk already settled once
                // reopens it rather than arriving pre-resolved.
                'dispute_resolved_at' => null,
            ])->save();

            $match->refresh();

            $this->history->handle(
                $match,
                MatchHistoryKind::Disputed,
                new MatchHistoryPayload(entrantId: $entrant->id, note: $reason),
                $disputedByUserId,
            );

            $disputed = new MatchDisputed(
                matchId: $match->id,
                competitionId: $match->competition_id,
                brandId: $match->brand_id,
                entrantId: $entrant->id,
                userId: $disputedByUserId,
                at: now()->toIso8601String(),
            );

            DB::afterCommit(static function () use ($disputed): void {
                event($disputed);
            });

            $this->tellTheOtherSide($match, $entrant);

            return $match->refresh();
        });
    }

    /**
     * §12's "a match was disputed" notice. Competition keeps no list of who
     * is on the desk (design §14, §8.4), so it goes to the people the
     * dispute is about: every roster member of the other side, whose claim or
     * recorded result has just been contested. The desk hears about it from
     * its own screen and from `KonventixCompetitionDisputeWaiting`.
     */
    private function tellTheOtherSide(CompetitionMatch $match, Entrant $entrant): void
    {
        $recipients = [];

        $others = MatchSlot::withoutBrandScope()
            ->where('competition_match_id', $match->id)
            ->whereNotNull('entrant_id')
            ->where('entrant_id', '!=', $entrant->id)
            ->orderBy('position')
            ->pluck('entrant_id');

        foreach ($others as $entrantId) {
            /** @var Entrant|null $side */
            $side = Entrant::withoutBrandScope()->whereKey((string) $entrantId)->first();

            $recipients = [...$recipients, ...($side?->memberUserIds() ?? [])];
        }

        /** @var CompetitionTeam|null $team */
        $team = CompetitionTeam::withoutBrandScope()->whereKey($entrant->competition_team_id)->first();

        $this->notifyRosterAfterCommit(
            $recipients,
            $match->brand_id,
            'competition.match-disputed',
            new EntrantNoticeData(
                competitionName: $this->competitionOf($match)->name,
                teamName: $team->name ?? '',
                detail: __('Euer Gegner hat das Ergebnis angefochten. Die Turnierleitung entscheidet.'),
            ),
        );
    }
}
