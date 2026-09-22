<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\TransitionsCompetitionStatus;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\SignupOpened;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * Puts a competition back on signup: `SignupClosed → Published`, the reopen
 * arrow of design §4.
 *
 * `SignupOpened` is emitted because §4 says the organizer reopening is one
 * of its two causes. Whether anybody can actually enter still depends on the
 * window — a reopen over a window that has already passed announces the
 * intent and leaves `signupIsOpen()` false, which is why the console offers
 * the signup dates on the same screen.
 */
final class ReopenSignup
{
    use TransitionsCompetitionStatus;

    public function handle(Competition $competition): Competition
    {
        return DB::transaction(function () use ($competition): Competition {
            $competition = $this->transition($competition, CompetitionStatus::Published);

            $entrantCount = Entrant::countSlotHolders($competition->brand_id, $competition->id);

            DB::afterCommit(static function () use ($competition, $entrantCount): void {
                event(new SignupOpened(
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
