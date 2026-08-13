<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets;

use Konventix\Competition\Brackets\Plan\BracketPlan;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchConnection;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Competition\Models\Stage;

/**
 * Turns a `BracketPlan` into matches, slots and edges.
 *
 * Extracted so the two things that write a plan — generating a whole bracket
 * and pairing one more Swiss round — cannot drift apart. `brand_id` is taken
 * from the stage rather than ambient context (ADR-0010), and getting that
 * wrong in one of two copies is exactly the bug this prevents.
 *
 * Not transactional on its own: both callers already own a transaction, and
 * nesting one here would make the boundary harder to see, not safer.
 */
final class PlanWriter
{
    /**
     * @return array<string, string> planned ref → persisted match id
     */
    public function write(Stage $stage, BracketPlan $plan): array
    {
        /** @var array<string, string> $idByRef */
        $idByRef = [];

        foreach ($plan->matches as $planned) {
            $match = new CompetitionMatch([
                'competition_id' => $stage->competition_id,
                'stage_id' => $stage->id,
                'round' => $planned->round,
                'position' => $planned->position,
                'bracket' => $planned->bracket,
                'state' => MatchState::Pending,
            ]);
            $match->forceFill(['brand_id' => $stage->brand_id])->save();

            $idByRef[$planned->ref] = $match->id;

            foreach ($planned->entrantIds as $position => $entrantId) {
                $slot = new MatchSlot([
                    'competition_match_id' => $match->id,
                    'entrant_id' => $entrantId,
                    'position' => $position,
                ]);
                $slot->forceFill(['brand_id' => $stage->brand_id])->save();
            }
        }

        foreach ($plan->connections as $connection) {
            $edge = new MatchConnection([
                'source_match_id' => $idByRef[$connection->sourceRef],
                'target_match_id' => $idByRef[$connection->targetRef],
                'source_outcome' => $connection->sourceOutcome,
                'target_slot' => $connection->targetSlot,
            ]);
            $edge->forceFill(['brand_id' => $stage->brand_id])->save();
        }

        return $idByRef;
    }
}
