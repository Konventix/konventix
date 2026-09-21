<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Messaging\Events\NotificationRead;
use Konventix\Messaging\Inbox\InboxScope;

/**
 * Marks every unread inbox notification of one recipient read, within what
 * the current host's bell shows (`InboxScope`: the brand's participant rows
 * on a brand host, the operator's notices on the admin host). One
 * `NotificationRead` per row — the contract event is per notification
 * (SPEC.md), and "mark all" is a burst of reads, not a different fact.
 */
final class MarkAllNotificationsRead
{
    public function handle(string $recipientId): int
    {
        return DB::transaction(function () use ($recipientId): int {
            // Resolved inside handle(): the scope reads request-scoped
            // contexts (`.ai/rules/listeners.md`).
            $unread = app(InboxScope::class)->for($recipientId)
                ->whereNull('read_at')
                ->lockForUpdate()
                ->get();

            $readAt = now();

            foreach ($unread as $notification) {
                $notification->forceFill(['read_at' => $readAt])->save();

                event(new NotificationRead(
                    notificationId: $notification->getKey(),
                    recipientId: $notification->recipient_id,
                    readAt: $readAt->toIso8601String(),
                ));
            }

            return $unread->count();
        });
    }
}
