<?php

declare(strict_types=1);

namespace Konventix\Messaging\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Messaging\Events\NotificationRead;
use Konventix\Messaging\Models\InboxNotification;

/**
 * Marks one inbox notification read (ADR-0041 point 6). Idempotent: a row
 * already read keeps its original `read_at` and emits nothing — read state
 * records when the person first saw it, not when they last clicked it.
 */
final class MarkNotificationRead
{
    public function handle(InboxNotification $notification): InboxNotification
    {
        return DB::transaction(function () use ($notification): InboxNotification {
            if (! $notification->isUnread()) {
                return $notification;
            }

            $notification->forceFill(['read_at' => now()])->save();

            event(new NotificationRead(
                notificationId: $notification->getKey(),
                recipientId: $notification->recipient_id,
                readAt: $notification->read_at?->toIso8601String() ?? now()->toIso8601String(),
            ));

            return $notification;
        });
    }
}
