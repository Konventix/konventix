<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Konventix\Messaging\Actions\MarkAllNotificationsRead;
use Konventix\Messaging\Actions\MarkNotificationRead;
use Konventix\Messaging\Data\NotificationData;
use Konventix\Messaging\Models\InboxNotification;

/**
 * The bell's endpoints (ADR-0041 point 6): plain JSON, not Inertia pages —
 * the bell lives in the persistent layout and fetches once per browser
 * page load (lessons-from-lancore #5: never on every request), then stays
 * current over Reverb. BrandScope narrows every query to the current
 * brand's rows by itself.
 */
class NotificationsController
{
    private const LIMIT = 15;

    public function index(Request $request): JsonResponse
    {
        $recipientId = (string) $request->user()->getKey();

        $notifications = InboxNotification::query()
            ->where('recipient_id', $recipientId)
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'unread' => InboxNotification::query()
                ->where('recipient_id', $recipientId)
                ->whereNull('read_at')
                ->count(),
            'notifications' => $notifications
                ->map(fn (InboxNotification $notification): NotificationData => NotificationData::ofModel($notification))
                ->all(),
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationRead $action): JsonResponse
    {
        $row = InboxNotification::query()
            ->whereKey($notification)
            ->where('recipient_id', (string) $request->user()->getKey())
            ->firstOrFail();

        return response()->json(NotificationData::ofModel($action->handle($row)));
    }

    public function readAll(Request $request, MarkAllNotificationsRead $action): JsonResponse
    {
        return response()->json([
            'read' => $action->handle((string) $request->user()->getKey()),
        ]);
    }
}
