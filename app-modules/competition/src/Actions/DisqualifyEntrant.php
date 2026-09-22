<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\RetiresAnEntrant;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;

/**
 * An organizer's ruling (design §5.5, `ManageEntrants`).
 *
 * The part that matters is what happens to the bracket. Disqualifying a
 * seeded entrant in a running bracket **forfeits its pending matches, each
 * recorded as a forfeit result through `RecordMatchResult`, so the bracket
 * advances the normal way**. No special "disqualified" edge, no branch in the
 * progression walk, nothing for `AdvanceBracket` to learn: the opponent wins
 * 1–0 and moves on exactly as they would have by playing.
 *
 * That is the whole reason the forfeit is a result rather than a state. A
 * bracket that advanced through a second mechanism would need every
 * standings calculator to know about it, and the one that forgot would
 * silently leave a hole in the table.
 *
 * A match where the disqualified side has no opponent yet — the slot is still
 * empty — is finished with no winner. Advancing an empty slot into the next
 * round would seed nobody; the slot simply stays open for whoever the other
 * feeder produces.
 */
final class DisqualifyEntrant
{
    use RetiresAnEntrant;

    /**
     * @return list<string> the matches forfeited
     */
    public function handle(Entrant $entrant): array
    {
        return DB::transaction(function () use ($entrant): array {
            $this->retireEntrant($entrant, EntrantState::Disqualified, WithdrawalReason::Disqualified);

            return $this->forfeitPendingMatches($entrant);
        });
    }

    /**
     * @return list<string>
     */
    private function forfeitPendingMatches(Entrant $entrant): array
    {
        $matchIds = MatchSlot::withoutBrandScope()
            ->where('brand_id', $entrant->brand_id)
            ->where('entrant_id', $entrant->getKey())
            ->pluck('competition_match_id');

        if ($matchIds->isEmpty()) {
            return [];
        }

        /** @var Collection<int, CompetitionMatch> $matches */
        $matches = CompetitionMatch::withoutBrandScope()
            ->whereIn('id', $matchIds)
            ->whereIn('state', [MatchState::Pending->value, MatchState::Scheduled->value, MatchState::Live->value])
            ->get();

        $forfeited = [];

        foreach ($matches as $match) {
            $slots = MatchSlot::withoutBrandScope()
                ->where('competition_match_id', $match->getKey())
                ->orderBy('position')
                ->get();

            $scores = [];
            $winnerId = null;

            foreach ($slots as $slot) {
                if ($slot->entrant_id === null) {
                    continue;
                }

                $isForfeiting = $slot->entrant_id === $entrant->getKey();
                $scores[(string) $slot->entrant_id] = $isForfeiting ? 0 : 1;

                if (! $isForfeiting) {
                    $winnerId = (string) $slot->entrant_id;
                }
            }

            app(RecordMatchResult::class)->handle($match, $scores, $winnerId);
            app(AdvanceBracket::class)->handle($match->refresh());

            $forfeited[] = (string) $match->getKey();
        }

        return $forfeited;
    }
}
