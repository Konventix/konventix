<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Competition\Models\CompetitionTeam;
use RuntimeException;

/**
 * A new invite code for a team (design §5.2). The captain's own remedy when
 * the old one ends up in a public channel, and an organizer's when the
 * captain has gone home.
 *
 * Who may ask is the caller's business — the controller checks captaincy, the
 * console checks `ManageEntrants`. This Action only guarantees that the team
 * comes out with a code nobody else in the brand holds.
 *
 * Uniqueness is the index's job, not a read-then-write check: two people
 * clicking "new code" at the same moment is exactly the race such a check
 * loses. A collision is retried, because the alternative — telling somebody
 * their new code could not be issued — is nonsense for an event with a
 * thirty-two-character alphabet and eight places.
 */
final class RegenerateInviteCode
{
    private const int MAX_ATTEMPTS = 10;

    public function handle(CompetitionTeam $team): string
    {
        return DB::transaction(function () use ($team): string {
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                $code = CompetitionTeam::generateInviteCode();

                try {
                    $team->forceFill(['invite_code' => $code])->save();

                    return $code;
                } catch (QueryException $e) {
                    if (! str_contains($e->getMessage(), 'invite_code')) {
                        throw $e;
                    }
                }
            }

            throw new RuntimeException(sprintf(
                'Could not find a free invite code for team [%s] in %d attempts.',
                $team->getKey(),
                self::MAX_ATTEMPTS,
            ));
        });
    }
}
