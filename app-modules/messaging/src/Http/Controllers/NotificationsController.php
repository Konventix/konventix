<?php

declare(strict_types=1);

namespace Konventix\Messaging\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Messaging\Actions\MarkAllNotificationsRead;
use Konventix\Messaging\Actions\MarkNotificationRead;
use Konventix\Messaging\Data\NotificationData;
use Konventix\Messaging\Inbox\InboxScope;
use Konventix\Messaging\Models\InboxNotification;

/**
 * The in-app inbox (ADR-0041 point 6), in two shapes for two readers.
 *
 * The bell lives in the persistent layout and fetches plain JSON once per
 * browser page load (lessons-from-lancore #5: never on every request), then
 * stays current over Reverb — so `index()` is deliberately not an Inertia
 * page. `page()` is the inbox a person opens: everything the bell's short
 * list leaves out, paginated.
 *
 * The two write endpoints answer both: JSON for the bell's `fetch`, a
 * redirect back for the page's Inertia visit. `InboxScope` decides which
 * rows the host serves: the current brand's participant rows on a brand
 * host, the operator's own notices across its brands on the admin host
 * (bugfix round 2026-09-21, Task 9).
 */
class NotificationsController
{
    private const LIMIT = 15;

    private const PER_PAGE = 25;

    public function __construct(private readonly InboxScope $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $recipientId = (string) $request->user()->getKey();

        $notifications = $this->inbox->for($recipientId)
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'unread' => $this->inbox->for($recipientId)
                ->whereNull('read_at')
                ->count(),
            'notifications' => $notifications
                ->map(fn (InboxNotification $notification): NotificationData => NotificationData::ofModel($notification))
                ->all(),
        ]);
    }

    /**
     * The inbox as a page. Read and unread together, newest first: an inbox
     * that hid what you had already read would make "what was that message
     * again?" unanswerable.
     */
    public function page(Request $request): Response
    {
        $recipientId = (string) $request->user()->getKey();

        $notifications = $this->inbox->for($recipientId)
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('notifications/Index', [
            'notifications' => $notifications->through(
                fn (InboxNotification $notification): NotificationData => NotificationData::ofModel($notification),
            ),
            'unread' => $this->inbox->for($recipientId)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationRead $action): JsonResponse|RedirectResponse
    {
        // Found through the same scope the feed lists it in: a row this
        // host does not serve is a 404 here too, not a read.
        $row = $this->inbox->for((string) $request->user()->getKey())
            ->whereKey($notification)
            ->firstOrFail();

        $updated = $action->handle($row);

        return $request->wantsJson()
            ? response()->json(NotificationData::ofModel($updated))
            : back();
    }

    public function readAll(Request $request, MarkAllNotificationsRead $action): JsonResponse|RedirectResponse
    {
        $read = $action->handle((string) $request->user()->getKey());

        return $request->wantsJson()
            ? response()->json(['read' => $read])
            : back();
    }
}
