<?php

declare(strict_types=1);

namespace Konventix\Competition\Actions;

use Illuminate\Validation\ValidationException;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\MatchHistoryEntry;

/**
 * Appends one row to a match's history (design §8.6).
 *
 * An Action like every other write (ADR-0015) and the **only** writer of
 * `competition_match_history`, which is what makes the timeline trustworthy:
 * the mandatory note §8.4 and §8.5 attach to `disputed`, `resolved` and
 * `voided_by_correction` is checked here, so no caller can write one of those
 * rows without a reason however it reaches the table.
 *
 * Deliberately not transactional on its own. Every caller already runs inside
 * the `DB::transaction` of the write it is recording, and a history row that
 * committed while the thing it describes rolled back would be worse than no
 * row at all.
 *
 * `brand_id` comes from the match, never from ambient context (ADR-0010): the
 * desk runs on the operator host, where no brand resolves (ADR-0029).
 */
final class RecordMatchHistory
{
    public function handle(
        CompetitionMatch $match,
        MatchHistoryKind $kind,
        ?MatchHistoryPayload $payload = null,
        ?string $actorUserId = null,
    ): MatchHistoryEntry {
        $payload ??= new MatchHistoryPayload;

        if (! $payload->satisfies($kind)) {
            throw ValidationException::withMessages([
                'note' => __('A :kind entry needs a note saying why.', ['kind' => $kind->value]),
            ]);
        }

        $entry = new MatchHistoryEntry([
            'competition_match_id' => $match->id,
            'kind' => $kind,
            'actor_user_id' => $actorUserId,
            'payload' => $payload->forStorage(),
        ]);

        $entry->forceFill(['brand_id' => $match->brand_id])->save();

        return $entry;
    }
}
