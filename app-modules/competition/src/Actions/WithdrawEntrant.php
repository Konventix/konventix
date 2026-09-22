<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\RetiresAnEntrant;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;

/**
 * A team leaves the field (design §5.5).
 *
 * Two callers with different reach, which is why the reason is a parameter
 * rather than something this Action guesses:
 *
 * - **The captain, until `Running`.** Pulling out before the bracket is drawn
 *   costs nobody anything; pulling out of a tournament that has started is
 *   not a self-service action, because the matches already scheduled against
 *   you belong to other people's evening.
 * - **The organizer, at any time** (`ManageEntrants`). A team that has gone
 *   home is a fact the desk has to be able to record whatever the clock says.
 *
 * A withdrawal in a running bracket deliberately does **not** forfeit the
 * pending matches — that is `DisqualifyEntrant`'s doing. The difference is
 * real: a team that withdraws is usually being replaced or the round is being
 * redrawn by hand, while a disqualification is a ruling that must advance the
 * bracket on its own.
 */
final class WithdrawEntrant
{
    use RetiresAnEntrant;

    public function handle(Entrant $entrant, WithdrawalReason $reason): Entrant
    {
        return DB::transaction(function () use ($entrant, $reason): Entrant {
            if ($reason === WithdrawalReason::CaptainWithdrew) {
                $this->guardBeforeRunning($entrant);
            }

            $this->retireEntrant($entrant, EntrantState::Withdrawn, $reason);

            return $entrant->refresh();
        });
    }

    private function guardBeforeRunning(Entrant $entrant): void
    {
        /** @var Competition|null $competition */
        $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->first();

        if ($competition !== null && $competition->status === CompetitionStatus::Running) {
            throw CompetitionEntryException::competitionOver((string) $competition->getKey());
        }
    }
}
