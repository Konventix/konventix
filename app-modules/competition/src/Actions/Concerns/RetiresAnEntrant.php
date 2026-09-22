<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions\Concerns;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Events\EntrantWithdrawn;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * The one way an entrant leaves the field (design §5.4, §5.5).
 *
 * Four Actions end an entry — a captain pulling the team out, an organizer
 * pulling it out, an organizer declining a roster that never filled, and a
 * disqualification — and all four owe the same three things: the terminal
 * state with its reason, the freed slot, and `EntrantWithdrawn` so Messaging
 * can tell the people involved and GameOps can tear down whatever it stood
 * up. Keeping that in one place is why the slot is never freed without the
 * event, which is the failure a hand-rolled fourth copy would eventually
 * introduce.
 *
 * The roster is read **before** the state changes: it is what the event
 * carries and who the notification goes to, and by the time a caller looks
 * again the organizer may already have moved somebody.
 */
trait RetiresAnEntrant
{
    /**
     * @return list<string> the roster as it stood when the entrant left
     */
    protected function retireEntrant(
        Entrant $entrant,
        EntrantState $finalState,
        WithdrawalReason $reason,
    ): array {
        if (in_array($entrant->state, [EntrantState::Withdrawn, EntrantState::Disqualified], true)) {
            throw CompetitionEntryException::entrantAlreadyLeft((string) $entrant->getKey());
        }

        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->first();

        if ($competition === null) {
            throw CompetitionEntryException::competitionOver($entrant->competition_id);
        }

        if (in_array($competition->status, [CompetitionStatus::Finished, CompetitionStatus::Cancelled], true)) {
            throw CompetitionEntryException::competitionOver((string) $competition->getKey());
        }

        $roster = $entrant->memberUserIds();

        $entrant->forceFill([
            'state' => $finalState,
            'withdrawal_reason' => $reason,
            'left_at' => now(),
        ])->save();

        $eventId = $competition->event_id;
        $brandId = $competition->brand_id;

        $withdrawn = new EntrantWithdrawn(
            entrantId: (string) $entrant->getKey(),
            competitionId: $entrant->competition_id,
            competitionTeamId: $entrant->competition_team_id,
            eventId: $eventId,
            brandId: $brandId,
            memberUserIds: $roster,
            reason: $reason->value,
            finalState: $finalState->value,
            withdrawnAt: now()->toIso8601String(),
        );

        DB::afterCommit(static function () use ($withdrawn): void {
            event($withdrawn);
        });

        return $roster;
    }
}
