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
    public function handle(Message $message, string $byUserId, string $reason): Message
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A removal needs a reason; the tombstone is what the desk reads back.');
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

        // After the commit — see `PostMessage::handle()` for why.
        event(new ShoutboxMessageRemoved(
            messageId: $message->id,
            roomId: $message->room_id,
            brandId: $message->brand_id,
            removedByUserId: $byUserId,
            reason: $reason,
            removedAt: $now->toIso8601String(),
        ));

        return $message;
    }
}
