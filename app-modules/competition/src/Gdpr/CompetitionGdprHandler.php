<?php

declare(strict_types=1);

namespace Konventix\Competition\Gdpr;

use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * Competition's contribution to GDPR export and deletion.
 *
 * The export shows which teams a person played in, in what role, and which
 * competitions they put their name down for.
 *
 * Three things **are** scrubbed on deletion. Two of them hold something other
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

        // The door read model follows Admission's own tombstone: the row
        // stays — the scan is the admission's — the person leaves it. The
        // roster check matches on `holder_user_id`, so nulling it is also
        // what stops the projection counting them.
        AdmissionPresence::withoutBrandScope()
            ->where('holder_user_id', $subject->userId)
            ->update(['holder_user_id' => null]);
    }
}
