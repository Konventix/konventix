<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * The desk takes a check-in back (design §6).
 *
 * **Desk only, whatever the mode.** §6 gives the desk both directions and
 * gives the captain only the first one: a team that checks itself in and then
 * wanders off is a fact for the desk to correct, and letting a captain undo
 * their own check-in minutes before the bracket is drawn would make the
 * field's readiness a thing participants could churn.
 *
 * The entrant goes back to `registered` with `checked_in_at` cleared, which
 * is exactly the state it left — so a re-check-in runs every guard again,
 * including the door requirement.
 *
 * No contract event, deliberately: design §12 names `EntrantCheckedIn` and
 * nothing around undoing it, and a "never mind" travelling as a fact would be
 * consumed as one. See the note on `Events\EntrantCheckedIn`.
 */
final class UndoCheckIn
{
    public function handle(Entrant $entrant): Entrant
    {
        return DB::transaction(function () use ($entrant): Entrant {
            /** @var Competition|null $competition */
            $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->first();

            if ($competition === null) {
                throw CompetitionEntryException::competitionOver($entrant->competition_id);
            }

            // A played-out or called-off competition is history and its field
            // does not change — the same line every entrant Action draws.
            if (in_array($competition->status, [
                CompetitionStatus::Finished,
                CompetitionStatus::Cancelled,
            ], true)) {
                throw CompetitionEntryException::competitionOver((string) $competition->getKey());
            }

            // Re-read under a row lock, like `CheckInEntrant` — two desk
            // lanes must not both undo the same check-in.
            /** @var Entrant $locked */
            $locked = Entrant::withoutBrandScope()->whereKey($entrant->getKey())->lockForUpdate()->firstOrFail();
            $entrant->setRawAttributes($locked->getAttributes(), true);

            if ($entrant->state !== EntrantState::CheckedIn) {
                throw CompetitionEntryException::entrantNotCheckedIn((string) $entrant->getKey());
            }

            $entrant->forceFill([
                'state' => EntrantState::Registered,
                'checked_in_at' => null,
            ])->save();

            return $entrant->refresh();
        });
    }
}
