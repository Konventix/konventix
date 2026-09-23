<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use RuntimeException;

/**
 * An organizer confirms a submitted score (design §7.2, §8.3).
 *
 * This is the moment a number starts counting. Until it runs, the entry sits
 * in the desk's verification queue and `LeaderboardStandingsCalculator` skips
 * it — so verifying is the write that moves the table, and the recompute
 * belongs here rather than in a listener that might not run.
 *
 * Capability: `RecordResults` (§8.3), enforced at the route by the console
 * slice that owns the queue screen. Rejecting an entry with a note is the
 * other half of §8.3 and lands with §8's own slice, together with the
 * disputes it shares a screen with.
 *
 * Verifying twice is a no-op rather than an error. Two desk volunteers
 * working the same queue is the expected failure, and the second click
 * should not be an exception — nor should it move `verified_at` and make the
 * audit trail say the later person was the one who looked at it.
 */
final class VerifyScoreEntry
{
    use GuardsCompetitionLifecycle;

    public function __construct(
        private readonly CalculateStandings $standings,
    ) {}

    public function handle(ScoreEntry $entry, string $verifiedByUserId): ScoreEntry
    {
        return DB::transaction(function () use ($entry, $verifiedByUserId): ScoreEntry {
            if ($entry->isVerified()) {
                return $entry;
            }

            /** @var Stage|null $stage */
            $stage = Stage::withoutBrandScope()->whereKey($entry->stage_id)->first();

            if ($stage === null) {
                throw new RuntimeException(sprintf(
                    'Score entry [%s] belongs to stage [%s], which no longer exists.',
                    $entry->id,
                    $entry->stage_id,
                ));
            }

            $this->guardStillBeingPlayed($stage->competition_id);

            $entry->forceFill([
                'verified_at' => now(),
                'verified_by_user_id' => $verifiedByUserId,
            ])->save();

            $this->standings->handle($stage);

            return $entry->refresh();
        });
    }
}
