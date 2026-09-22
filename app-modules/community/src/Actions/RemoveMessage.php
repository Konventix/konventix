<?php

declare(strict_types=1);

namespace Konventix\Community\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Community\Events\ShoutboxMessageRemoved;
use Konventix\Community\Models\Message;

/**
 * Removes one shoutbox line (spec §6, D-COM-08; ADR-0015).
 *
 * **The removal is a tombstone, not a delete** (spec §4.2). The row stays
 * so that a report naming it still resolves and the desk can see what it
 * acted on; the read path renders it as "Von der Orga entfernt" and drops
 * its body and author before it reaches other participants. `Message`
 * deliberately does **not** use Eloquent's `SoftDeletes` — that trait's
 * global scope would hide exactly the row this feature needs visible — so
 * there is no `withTrashed()` to reach for and no `restore()` to call.
 *
 * **Nothing but this Action keeps the tombstone triple consistent.** There
 * is no database CHECK tying `deleted_at`, `deleted_by_user_id` and
 * `deleted_reason` together, so all three are written in one `forceFill`
 * inside one transaction: a row with a `deleted_at` and no reason is a
 * tombstone the console cannot explain.
 *
 * Removing an already-removed line is a no-op that dispatches nothing. A
 * double-clicked button is the ordinary way this happens, and re-firing
 * `ShoutboxMessageRemoved` would put one act in a consumer's hands twice
 * while quietly rewriting who removed it and why.
 *
 * The capability gate is `Permission::ModerateCommunity` and is the
 * surface's job (D-COM-04): moderation deliberately does **not** require
 * admission, so nothing here reads `community_event_presence`.
 */
final class RemoveMessage
{
    /**
     * The `varchar(255)` of `community_messages.deleted_reason` (spec §4.2),
     * checked here rather than left to the column: an overflow is a 500 and,
     * worse, a constraint violation that aborts whatever transaction the
     * console had open around it (`.ai/rules/tests.md`).
     */
    public const int MAX_REASON_LENGTH = 255;

    public function handle(Message $message, string $byUserId, string $reason): Message
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A removal needs a reason; the tombstone is what the desk reads back.');
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A removal reason is at most %d characters; `deleted_reason` is a varchar(%d).',
                self::MAX_REASON_LENGTH,
                self::MAX_REASON_LENGTH,
            ));
        }

        if ($message->isRemoved()) {
            return $message;
        }

        $now = CarbonImmutable::now()->utc();

        DB::transaction(function () use ($message, $byUserId, $reason, $now): void {
            // All three together: no constraint ties them, so this is it.
            $message->forceFill([
                'deleted_at' => $now,
                'deleted_by_user_id' => $byUserId,
                'deleted_reason' => $reason,
            ])->save();
        });

        // After the commit, and through `DB::afterCommit()` so that it is
        // still after the commit when a caller wraps this Action in a
        // transaction of its own — see `PostMessage::handle()` for why.
        $removed = new ShoutboxMessageRemoved(
            messageId: $message->id,
            roomId: $message->room_id,
            brandId: $message->brand_id,
            removedByUserId: $byUserId,
            reason: $reason,
            removedAt: $now->toIso8601String(),
        );

        DB::afterCommit(static fn () => event($removed));

        return $message;
    }
}
