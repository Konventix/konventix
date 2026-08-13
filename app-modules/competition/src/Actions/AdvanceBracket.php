<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
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

            return array_values(array_unique($touched));
        });
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
