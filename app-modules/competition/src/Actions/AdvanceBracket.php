<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;

/**
 * Walks a finished match's outgoing edges and drops its winner — and, in
 * double elimination, its loser — into the slots they feed.
 *
 * One implementation for every format, because the progression graph is
 * explicit. LanBrackets had a Resolver per format; the shapes differ only in
 * *which* edges the generator created, so resolving them is the same walk
 * regardless. That is the payoff of storing edges instead of computing
 * positions.
 *
 * Idempotent: filling a slot that already holds the same entrant is a no-op,
 * so re-advancing after a correction cannot duplicate anyone.
 *
 * A filled slot can be the last one a **timed** match was waiting for: the
 * desk schedules whole rounds before the previous round is decided. That is
 * the moment the match has its time and its sides, so this is where
 * `MatchScheduled` goes out for it (`ScheduleMatch::announce()`, design §14,
 * "§12 reconciled"). A no-op fill announces nothing, so re-advancing after a
 * correction cannot announce a match twice.
 *
 * ## A match nobody can reach is closed
 *
 * An edge can deliver nobody: a bye has no loser, and a match a
 * disqualification finished has no winner. When every edge into a match has
 * delivered and none of them delivered anybody, the match has no side, and
 * nothing can ever give it one. Left open it dead-ends the bracket: the desk
 * cannot record a match against nobody, and the slot it feeds downstream
 * reads as "still to come" for ever. Double elimination with five entrants
 * does this without any disqualification — two winners'-bracket byes feed
 * the same losers'-bracket round-1 match (final review, fix wave A round 2,
 * N1).
 *
 * So after the edges are walked, every match they point into is asked: open,
 * no side, fed only by finished matches? Then it is finished with no winner —
 * "nobody" travels on, and the match downstream sees an empty slot behind a
 * finished feeder, which is the bye rule. The closing advances in turn, so a
 * chain of such matches closes in one pass. It sends no `MatchFinished`:
 * nothing was played, and nothing was ever announced for it.
 */
final class AdvanceBracket
{
    /**
     * @return list<string> ids of matches whose slots were filled
     */
    public function handle(CompetitionMatch $match): array
    {
        return DB::transaction(function () use ($match): array {
            $loserId = $this->loserOf($match);
            $touched = [];

            $edges = MatchConnection::withoutBrandScope()
                ->where('brand_id', $match->brand_id)
                ->where('source_match_id', $match->id)
                ->get();

            foreach ($edges as $edge) {
                $entrantId = $edge->source_outcome === MatchConnection::OUTCOME_WINNER
                    ? $match->winner_entrant_id
                    : $loserId;

                if ($entrantId === null) {
                    continue;
                }

                $slot = MatchSlot::withoutBrandScope()
                    ->where('brand_id', $match->brand_id)
                    ->where('competition_match_id', $edge->target_match_id)
                    ->where('position', $edge->target_slot)
                    ->first();

                if ($slot === null || $slot->entrant_id === $entrantId) {
                    continue;
                }

                $slot->forceFill(['entrant_id' => $entrantId])->save();
                $touched[] = $edge->target_match_id;
            }

            $touched = array_values(array_unique($touched));

            foreach ($touched as $targetId) {
                /** @var CompetitionMatch|null $target */
                $target = CompetitionMatch::withoutBrandScope()->whereKey($targetId)->first();

                if ($target !== null) {
                    app(ScheduleMatch::class)->announce($target);
                }
            }

            foreach (array_values(array_unique($edges->pluck('target_match_id')->all())) as $targetId) {
                $this->closeIfNobodyCanArrive((string) $targetId);
            }

            return $touched;
        });
    }

    /**
     * Finishes, with no winner, an open match that has no side and whose
     * every feeding match is finished — see the class docblock.
     */
    private function closeIfNobodyCanArrive(string $matchId): void
    {
        /** @var CompetitionMatch|null $match */
        $match = CompetitionMatch::withoutBrandScope()->whereKey($matchId)->lockForUpdate()->first();

        if ($match === null || ! in_array($match->state, [MatchState::Pending, MatchState::Scheduled, MatchState::Live], true)) {
            return;
        }

        $hasASide = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->whereNotNull('entrant_id')
            ->exists();

        if ($hasASide) {
            return;
        }

        $feeders = MatchConnection::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('target_match_id', $match->id)
            ->pluck('source_match_id')
            ->all();

        $stillPlaying = CompetitionMatch::withoutBrandScope()
            ->whereIn('id', $feeders)
            ->where('state', '!=', MatchState::Finished->value)
            ->exists();

        if ($feeders === [] || $stillPlaying) {
            return;
        }

        $match->forceFill([
            'state' => MatchState::Finished,
            'winner_entrant_id' => null,
            'finished_at' => now(),
        ])->save();

        // German: the sides read the timeline on the match page (ADR-0001).
        app(RecordMatchHistory::class)->handle(
            $match,
            MatchHistoryKind::Recorded,
            new MatchHistoryPayload(note: __('Keines der Spiele davor hat ein Team hierher geschickt; das Spiel entfällt.')),
        );

        $this->handle($match->refresh());
    }

    /**
     * The side that did not win. Null when the match had one participant (a
     * bye) or no winner at all.
     */
    private function loserOf(CompetitionMatch $match): ?string
    {
        if ($match->winner_entrant_id === null) {
            return null;
        }

        $entrantIds = MatchSlot::withoutBrandScope()
            ->where('brand_id', $match->brand_id)
            ->where('competition_match_id', $match->id)
            ->whereNotNull('entrant_id')
            ->pluck('entrant_id')
            ->all();

        $losers = array_values(array_filter(
            array_map(strval(...), $entrantIds),
            fn (string $id): bool => $id !== $match->winner_entrant_id,
        ));

        return count($losers) === 1 ? $losers[0] : null;
    }
}
