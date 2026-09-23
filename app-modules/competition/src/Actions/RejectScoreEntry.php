<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Actions\Concerns\GuardsCompetitionLifecycle;
use Konventix\Competition\Models\ScoreEntry;
use Konventix\Competition\Models\Stage;
use RuntimeException;

/**
 * An organizer turns a submitted score down (design §8.3).
 *
 * §8.3 gives the desk two answers to a score entry — "until an organizer
 * verifies it (`VerifyScoreEntry`, capability `RecordResults`), or **rejects
 * it with a note**" — and only the first had anywhere to live until now.
 *
 * The row **stays**, with its value, its proof and a reason. Deleting it
 * would tell the participant nothing: what they need is to see what was wrong
 * with the number so they can submit a better one, and what the desk needs is
 * a record that it looked at this and said no. `note` remains the submitter's
 * own remark; the organizer's goes in `rejection_note`, because the two are
 * different people's words and merging them would misattribute one of them.
 *
 * A rejected entry does not count, which needs no new rule:
 * `LeaderboardStandingsCalculator` ranks verified entries and nothing else,
 * and rejecting clears `verified_at`. That clearing is also why the standings
 * are recomputed here — an organizer who verified a score and then found it
 * wrong must not leave the table standing on it.
 *
 * Resubmission is `SubmitScoreEntry`, which clears the rejection as it
 * replaces the value: a rejected entry is an unverified one, so the
 * participant may replace it (§8.3's ladder), and the old reason must not
 * cling to the new number.
 *
 * What a participant does when they think the rejection is wrong is §8.4's
 * business — "a rejected score entry that the participant contests" is
 * resolved by the desk, on the same screen as a match dispute — and a
 * leaderboard stage has no match to hang a dispute on. In this slice that
 * conversation happens beside the queue rather than in it; the desk can
 * always verify an entry it rejected in error.
 */
final class RejectScoreEntry
{
    use GuardsCompetitionLifecycle;

    public function __construct(
        private readonly CalculateStandings $standings,
    ) {}

    public function handle(ScoreEntry $entry, string $rejectedByUserId, string $note): ScoreEntry
    {
        return DB::transaction(function () use ($entry, $rejectedByUserId, $note): ScoreEntry {
            if (trim($note) === '') {
                throw ValidationException::withMessages([
                    'rejectionNote' => __('Say why the score is not accepted; the participant is shown the reason.'),
                ]);
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

            $countedBefore = $entry->isVerified();

            $entry->forceFill([
                'verified_at' => null,
                'verified_by_user_id' => null,
                'rejected_at' => now(),
                'rejected_by_user_id' => $rejectedByUserId,
                'rejection_note' => $note,
            ])->save();

            if ($countedBefore) {
                $this->standings->handle($stage);
            }

            return $entry->refresh();
        });
    }
}
