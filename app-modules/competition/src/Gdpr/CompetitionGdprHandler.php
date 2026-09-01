<?php

declare(strict_types=1);

namespace Konventix\Competition\Gdpr;

use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\GdprHandler;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * Competition's contribution to GDPR export and deletion.
 *
 * Same shape as Staffing's: the export shows which teams a person played in,
 * and deletion has nothing to scrub because a membership row is a pair of
 * ULIDs. Results and standings are a public record of a competition that took
 * place — removing a player from a finished bracket would falsify the
 * tournament rather than protect them, and the name is rendered from
 * Identity's tombstone in any case.
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
                    'joined_at' => $member->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function delete(SubjectRef $subject, DeletionMode $mode): void
    {
        // Nothing to scrub — see the class docblock.
    }
}
