<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\ForfeitsOpenMatches;
use Konventix\Competition\Actions\Concerns\NotifiesRosterMembers;
use Konventix\Competition\Actions\Concerns\RetiresAnEntrant;
use Konventix\Competition\Data\EntrantNoticeData;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;

/**
 * The other answer to §5.4's prompt: the roster never filled up, so the entry
 * is dropped and its slot comes free for somebody who can field a full team.
 *
 * `withdrawal_reason` is `incomplete_roster`, which is the one reason an
 * organizer never types: it is written here, and it is what lets the
 * participant page say "nicht vollzählig" rather than the blank "withdrawn"
 * that would read like the team gave up.
 *
 * The team's members are told. Being quietly dropped from a tournament you
 * signed up for and finding out from the bracket is the thing this message
 * exists to prevent.
 */
final class DeclineIncompleteEntrant
{
    use ForfeitsOpenMatches;
    use NotifiesRosterMembers;
    use RetiresAnEntrant;

    public function handle(Entrant $entrant): Entrant
    {
        return DB::transaction(function () use ($entrant): Entrant {
            $this->lockCompetitionOf($entrant);

            if ($entrant->state !== EntrantState::Incomplete) {
                throw CompetitionEntryException::entrantNotIncomplete((string) $entrant->getKey());
            }

            /** @var Competition $competition */
            $competition = Competition::withoutBrandScope()->whereKey($entrant->competition_id)->firstOrFail();

            /** @var CompetitionTeam $team */
            $team = CompetitionTeam::withoutBrandScope()->whereKey($entrant->competition_team_id)->firstOrFail();

            $roster = $this->retireEntrant(
                $entrant,
                EntrantState::Withdrawn,
                WithdrawalReason::IncompleteRoster,
            );

            // A roster that fell short after the draw can be in the bracket;
            // once a result stands it is forfeited out of it rather than
            // left to strand the start (fix wave A round 2, N2).
            if ($this->competitionHasARecordedResult($entrant)) {
                $this->forfeitOpenMatches($entrant);
                $this->recomputeCompetition($entrant);
            }

            // After the commit and only where the brand has the module on,
            // like every other roster notice (final review, part 1).
            $this->notifyRosterAfterCommit(
                $roster,
                $competition->brand_id,
                'competition.entry-declined',
                new EntrantNoticeData(
                    competitionName: $competition->name,
                    teamName: $team->name,
                    detail: WithdrawalReason::IncompleteRoster->label(),
                ),
            );

            return $entrant->refresh();
        });
    }
}
