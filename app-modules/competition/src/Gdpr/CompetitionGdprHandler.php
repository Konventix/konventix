<?php

declare(strict_types=1);

namespace Konventix\Competition\Gdpr;

use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\LinkedIdentity;
use Konventix\Competition\Models\MatchHistoryEntry;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * Competition's contribution to GDPR export and deletion.
 *
 * The export shows which teams a person played in, in what role, and which
 * competitions they put their name down for, and which provider accounts
 * this module knows they have linked.
 *
 * Five things **are** scrubbed on deletion. Two of them hold something other
 * than ULIDs: the `ParticipantProfile` row, which carries a display name and
 * a username, and the interested-list rows, whose free-text note the person
 * wrote themselves and which are public on the competition page.
 *
 * The third is `competition_admission_presence.holder_user_id`, which is a
 * user ULID — the one place this module records that a named person was
 * physically at a door at a time. The row survives and the person leaves it,
 * which is the shape Admission's own tombstone has and the shape Resources
 * already uses for the same column (`ResourcesGdprHandler`, #292 §10): the
 * scan is the *admission's* fact and the count of who came through the
 * entrance must not move because somebody asked to be forgotten. Export is
 * not owed for it — it is a projection of facts Admission's own handler
 * exports, and §13's list of what this module exports does not name it.
 *
 * The fourth, the **results trail**, is scrubbed the same way: every user
 * ULID on a score entry (`submitted_by_user_id`, `verified_by_user_id`,
 * `rejected_by_user_id`), on a side's result report (`reported_by_user_id`)
 * and on a match-history row (`actor_user_id`) is nulled, and every row
 * stays. A score and a report are the competition's history — the entrant
 * they belong to is a team, not the person — while *who typed it* is the
 * person, and is forgotten. Nulling rather than a tombstone ULID follows
 * Registration's `EntryConditionAssertion` (`subject_user_id`,
 * `decided_by_user_id`) and Resources' `created_by` / `holder_id`: this repo
 * forgets an author on a row that must survive by nulling the reference, and
 * the columns are nullable for exactly that reason.
 *
 * The fifth is the **linked-account read model**
 * (`competition_linked_identities`, design §5.6). It records which provider
 * accounts the person has linked, and it is exported and deleted outright.
 * It is a projection of Identity's data that no finished competition depends
 * on, so nothing needs to survive the person.
 *
 * Nothing else is. Results, standings and team membership are a public record
 * of a competition that took place — removing a player from a finished
 * bracket would falsify the tournament rather than protect them, and a
 * membership row is a pair of ULIDs whose name now renders from Identity's
 * tombstone through the projection above.
 */
final class CompetitionGdprHandler implements GdprHandler
{
    public function module(): string
    {
        return 'competition';
    }

    public function exportFor(SubjectRef $subject): array
    {
        if (! $subject->isAccountBacked()) {
            return [];
        }

        $subjectId = $subject->userId;

        return [
            'team_memberships' => CompetitionTeamMember::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CompetitionTeamMember $member): array => [
                    'competition_team_id' => $member->competition_team_id,
                    'role' => $member->role->value,
                    'joined_at' => $member->created_at?->toIso8601String(),
                ])
                ->all(),
            'interests' => CompetitionInterest::withoutBrandScope()
                ->where('user_id', $subjectId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CompetitionInterest $interest): array => [
                    'competition_id' => $interest->competition_id,
                    'note' => $interest->note,
                    'registered_at' => $interest->created_at?->toIso8601String(),
                ])
                ->all(),
            'linked_accounts' => LinkedIdentity::query()
                ->where('user_id', $subjectId)
                ->orderBy('provider')
                ->get()
                ->map(fn (LinkedIdentity $identity): array => [
                    'provider' => $identity->provider,
                    'linked_at' => $identity->linked_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        if (! $subject->isAccountBacked()) {
            return;
        }

        // The name read model and the public notes — see the class docblock
        // for what is deliberately left standing.
        ParticipantProfile::query()->whereKey($subject->userId)->delete();

        CompetitionInterest::withoutBrandScope()
            ->where('user_id', $subject->userId)
            ->delete();

        // Which provider accounts they linked (design §5.6): a projection of
        // Identity's data with no history to keep.
        LinkedIdentity::query()
            ->where('user_id', $subject->userId)
            ->delete();

        // The door read model follows Admission's own tombstone: the row
        // stays — the scan is the admission's — the person leaves it. The
        // roster check matches on `holder_user_id`, so nulling it is also
        // what stops the projection counting them.
        AdmissionPresence::withoutBrandScope()
            ->where('holder_user_id', $subject->userId)
            ->update(['holder_user_id' => null]);

        // The results trail: the rows are the competition's history, the
        // person who submitted, verified, rejected, reported or acted on
        // them is not (see the class docblock). One update per column, so a
        // row where the person held two roles — the desk verifying its own
        // entry — loses both references. Idempotent: a second run matches
        // nothing.
        foreach (['submitted_by_user_id', 'verified_by_user_id', 'rejected_by_user_id'] as $column) {
            ScoreEntry::withoutBrandScope()
                ->where($column, $subject->userId)
                ->update([$column => null]);
        }

        MatchResultReport::withoutBrandScope()
            ->where('reported_by_user_id', $subject->userId)
            ->update(['reported_by_user_id' => null]);

        MatchHistoryEntry::withoutBrandScope()
            ->where('actor_user_id', $subject->userId)
            ->update(['actor_user_id' => null]);
    }
}
