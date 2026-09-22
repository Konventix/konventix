<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\SignupClosed;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * Shuts signup: `Published → SignupClosed` (D-CMP-15, design §4).
 *
 * The state that exists so an organizer can stop taking entries *without*
 * starting anything — incomplete rosters get resolved here (§5.4), the
 * bracket is seeded and generated here, and only then does the tournament
 * start. Called by the console and, once §4's scheduler tick lands, by the
 * clock when `signup_closes_at` passes.
 *
 * The window itself is deliberately left alone: the state is what closes
 * signup (`Competition::signupIsOpen()` refuses outside `Published`
 * whatever the dates say), so an early close does not rewrite the dates the
 * organizer announced.
 */
final class CloseSignup
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $competition = $this->transition($competition, CompetitionStatus::SignupClosed);

            // Every entrant holding a slot, `incomplete` ones included: they
            // are exactly what the organizer is about to resolve (§5.4), and
            // a count that hid them would understate the field.
            $entrantCount = Entrant::countSlotHolders($competition->brand_id, $competition->id);

            DB::afterCommit(static function () use ($competition, $entrantCount): void {
                event(new SignupClosed(
                    competitionId: $competition->id,
                    eventId: $competition->event_id,
                    brandId: $competition->brand_id,
                    entrantCount: $entrantCount,
                    at: now()->toIso8601String(),
                ));
            });

            return $competition;
        });
    }
}
