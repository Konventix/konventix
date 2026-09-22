<?php

declare(strict_types=1);

namespace Konventix\Community\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Community\Actions\PostMessage;
use Konventix\Community\Actions\ReportMessage;
use Konventix\Community\Data\PostMessageData;
use Konventix\Community\Data\ReportData;
use Konventix\Community\Data\ShoutboxPresenceData;
use Konventix\Community\Data\ShoutboxRoomData;
use Konventix\Community\Enums\ConnectionStatus;
use Konventix\Community\Exceptions\ShoutboxWriteException;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\Room;
use Konventix\Community\Presence\ConnectionState;
use Konventix\Community\Rooms\EventRooms;
use Konventix\Community\Rooms\WallReader;
use Konventix\Platform\Hub\ActiveEventContext;

/**
 * The participant's room at `/live/shoutbox` (design §8) and the few JSON
 * endpoints the room page and the hub card talk to.
 *
 * **Admitted participants only** (D-COM-03). Every method resolves the room
 * through `EventRooms`, which derives it from the member's own running
 * event and lets them in only when `RoomChannel::join()` does; anyone else
 * gets a 404 — "there is no wall for you here", the same answer whether the
 * member was never admitted or the admission ended. The presence heartbeat
 * is the one exception, and says why.
 *
 * **Thin** (ADR-0015): the writes are `PostMessage` and `ReportMessage`,
 * the reads are `WallReader`'s. A refusal from either Action becomes a
 * validation error on the field it names, through
 * `ShoutboxWriteException::toValidationException()` — this class never
 * branches on a cause and never reads the German sentence.
 *
 * The JSON endpoints answer the room page's and the card's `fetch()`
 * calls; the page itself is an Inertia visit.
 */
final class RoomController
{
    public function __construct(
        private readonly EventRooms $rooms,
        private readonly WallReader $wall,
    ) {}

    public function show(Request $request): Response
    {
        $room = $this->room($request);

        return Inertia::render('community/live/Room', [
            'room' => new ShoutboxRoomData(
                eventName: app(ActiveEventContext::class)->current()?->eventName,
                wall: $this->wall->wall($room, $this->viewerId($request), WallReader::PAGE_SIZE),
                presence: $this->wall->presence($room),
            ),
        ]);
    }

    /**
     * One page of the wall by id: `after` for the reconciliation a client
     * runs when its socket comes back, `before` for scrolling upward.
     * `since` — the `syncedAt` of the client's previous answer — adds the
     * removals of lines it already holds (`WallReader::after()`).
     */
    public function messages(Request $request): JsonResponse
    {
        $room = $this->room($request);

        $validated = $request->validate([
            'after' => ['required_without:before', 'nullable', 'ulid'],
            'before' => ['required_without:after', 'nullable', 'ulid'],
            'since' => ['nullable', 'date'],
        ]);

        $after = $validated['after'] ?? null;
        $before = $validated['before'] ?? null;
        $since = $validated['since'] ?? null;

        $feed = is_string($after)
            ? $this->wall->after($room, $after, is_string($since) ? CarbonImmutable::parse($since)->utc() : null)
            : $this->wall->before($room, (string) $before);

        return new JsonResponse($feed->toArray());
    }

    /**
     * `throttle:shoutbox-post` has already spent the coarse half of
     * D-COM-09's budget; `PostMessage` holds the per-room half and every
     * other rule.
     */
    public function store(Request $request, PostMessage $postMessage): JsonResponse
    {
        $room = $this->room($request);
        $data = PostMessageData::from($request);

        try {
            $message = $postMessage->handle($room, $this->viewerId($request), $data);
        } catch (ShoutboxWriteException $refusal) {
            throw $refusal->toValidationException();
        }

        return new JsonResponse($this->wall->line($message)->toArray(), 201);
    }

    /**
     * Any participant may report a line of this room once (spec §4.4) —
     * including one the desk has already removed, which is still a fact
     * about that line. `ReportData::from($request)` enforces the note's
     * `MAX_NOTE_LENGTH` at the form; `ReportMessage` enforces it again.
     */
    public function report(Request $request, string $message, ReportMessage $reportMessage): HttpResponse
    {
        $room = $this->room($request);
        $data = ReportData::from($request);

        $reported = Message::query()->where('room_id', $room->id)->findOrFail($message);

        try {
            $reportMessage->handle($reported, $this->viewerId($request), $data);
        } catch (ShoutboxWriteException $refusal) {
            throw $refusal->toValidationException();
        }

        return response()->noContent();
    }

    /**
     * The room page's heartbeat (design §5): this account is connected and
     * looking at this room. Answers with the presence list, so the page
     * refreshes it on the same beat.
     *
     * **No gate of its own, and that is deliberate.** `enterRoom()`
     * authorises itself through `RoomChannel::join()` and throws without
     * writing anything; this turns that into the 403 it is. Only the room
     * is resolved here — from the member's running event, never from the
     * client — and the connection axis is written after `enterRoom()` has
     * said yes, so a refused heartbeat records nothing at all.
     */
    public function presence(Request $request, ConnectionState $connections): JsonResponse
    {
        $room = $this->unauthorisedRoom();

        $validated = $request->validate([
            'status' => ['required', Rule::in([ConnectionStatus::Online->value, ConnectionStatus::Away->value])],
        ]);

        try {
            $connections->enterRoom($room, $this->viewer($request));
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($validated['status'] === ConnectionStatus::Away->value) {
            $connections->away($room->brand_id, $this->viewer($request));
        } else {
            $connections->online($room->brand_id, $this->viewer($request));
        }

        return new JsonResponse(array_map(
            static fn (ShoutboxPresenceData $row): array => $row->toArray(),
            $this->wall->presence($room),
        ));
    }

    /**
     * Leaving needs no authorisation: it only ever removes the caller's own
     * entry (`ConnectionState::leaveRoom()`).
     */
    public function leave(Request $request, ConnectionState $connections): HttpResponse
    {
        $connections->leaveRoom($this->unauthorisedRoom(), $this->viewer($request));

        return response()->noContent();
    }

    private function room(Request $request): Room
    {
        return $this->rooms->forViewer($this->viewer($request)) ?? abort(404);
    }

    /**
     * The running event's room without the channel's decision — for the two
     * presence endpoints, whose `ConnectionState` methods decide for
     * themselves. Still 404 for a member with no running event: there is no
     * room to name.
     */
    private function unauthorisedRoom(): Room
    {
        $event = app(ActiveEventContext::class)->current() ?? abort(404);

        return $this->rooms->ofEvent($event->eventId) ?? abort(404);
    }

    /**
     * Every route here sits behind `auth`; the 401 only makes that visible
     * to the type checker.
     */
    private function viewer(Request $request): Authenticatable
    {
        return $request->user() ?? abort(401);
    }

    private function viewerId(Request $request): string
    {
        return (string) $this->viewer($request)->getAuthIdentifier();
    }
}
