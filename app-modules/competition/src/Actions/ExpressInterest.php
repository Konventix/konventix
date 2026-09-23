<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;

/**
 * "I would play this" (design §5.3).
 *
 * The gate is §5.1's — a confirmed registration for the event — because the
 * list is public and its whole purpose is that people find each other: a list
 * anybody could write into would be a recruiting board for people who are not
 * coming.
 *
 * Idempotent on `(competition_id, user_id)`: someone re-submitting with a
 * different note is editing their entry, not creating a second one, which is
 * also what the unique index would insist on.
 *
 * Refused for somebody already on an entered roster — they are not looking.
 * That is the same fact `MaintainsTeamRoster::retireInterest()` enforces from
 * the other direction when a join happens.
 */
final class ExpressInterest
{
    public function handle(Competition $competition, string $userId, ?string $note = null): CompetitionInterest
    {
        return DB::transaction(function () use ($competition, $userId, $note): CompetitionInterest {
            // Only while a team can still be found (final review, part 2,
            // M4): the page 404s a draft, and nothing is left to find once
            // the competition runs or is over.
            if (! in_array($competition->status, [CompetitionStatus::Published, CompetitionStatus::SignupClosed], true)) {
                throw CompetitionEntryException::interestClosed($competition->id, $competition->status->value);
            }

            if (! EligibleRegistration::userMayEnter($competition->brand_id, $competition->event_id, $userId)) {
                throw CompetitionEntryException::noConfirmedRegistration($userId, $competition->event_id);
            }

            $teamId = $this->enteredTeamOf($competition, $userId);

            if ($teamId !== null) {
                throw CompetitionEntryException::alreadyOnTeam($userId, $teamId);
            }

            /** @var CompetitionInterest|null $existing */
            $existing = CompetitionInterest::withoutBrandScope()
                ->where('brand_id', $competition->brand_id)
                ->where('competition_id', $competition->getKey())
                ->where('user_id', $userId)
                ->first();

            if ($existing !== null) {
                $existing->forceFill(['note' => $note])->save();

                return $existing;
            }

            $interest = new CompetitionInterest([
                'competition_id' => (string) $competition->getKey(),
                'user_id' => $userId,
                'note' => $note,
            ]);
            // brand_id from the competition, never ambient context (ADR-0010).
            $interest->forceFill(['brand_id' => $competition->brand_id]);
            $interest->save();

            return $interest;
        });
    }

    /**
     * The team this person already plays for in this competition, if any.
     */
    private function enteredTeamOf(Competition $competition, string $userId): ?string
    {
        $teamIds = Entrant::withoutBrandScope()
            ->where('brand_id', $competition->brand_id)
            ->where('competition_id', $competition->getKey())
            ->whereIn('state', Entrant::slotHoldingStates())
            ->pluck('competition_team_id');

        foreach ($teamIds as $teamId) {
            /** @var CompetitionTeam|null $team */
            $team = CompetitionTeam::withoutBrandScope()->whereKey($teamId)->first();

            if ($team !== null && $team->hasMember($userId)) {
                return (string) $team->getKey();
            }
        }

        return null;
    }
}
