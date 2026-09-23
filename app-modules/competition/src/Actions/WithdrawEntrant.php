<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\ForfeitsOpenMatches;
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
 * A withdrawal forfeits the team's open matches only **once a result
 * stands** in the competition. Before that the desk can still draw again, and
 * a team that withdraws is usually being replaced or the round redrawn by
 * hand, so the bracket is left for the desk. After it no redraw exists, and a
 * withdrawn team left in its slots would strand the bracket — or, before the
 * start, the start itself, whose changed-field check could then never pass
 * (design §14, fix wave A round 2, N2).
 */
final class WithdrawEntrant
{
    use ForfeitsOpenMatches;
    use RetiresAnEntrant;

    public function handle(Entrant $entrant, WithdrawalReason $reason): Entrant
    {
        return DB::transaction(function () use ($entrant, $reason): Entrant {
            $this->lockCompetitionOf($entrant);

            if ($reason === WithdrawalReason::CaptainWithdrew) {
                $this->guardBeforeRunning($entrant);
            }

            $this->retireEntrant($entrant, EntrantState::Withdrawn, $reason);

            // Once a result stands the desk cannot draw again, so the team
            // must not be left in its slots: its open matches are forfeited
            // as a disqualification's are (fix wave A round 2, N2). Without
            // a result the sweep does nothing, and the first result sweeps
            // the team out later (round 3, M-f).
            app(ForfeitRetiredEntrants::class)->handle($entrant->competition_id, $entrant->brand_id);

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
