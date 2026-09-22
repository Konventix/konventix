<?php

declare(strict_types=1);

namespace Konventix\Community\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Community\Actions\LockRoom;
use Konventix\Community\Actions\MuteParticipant;
use Konventix\Community\Actions\RemoveMessage;
use Konventix\Community\Actions\ResolveReport;
use Konventix\Community\Actions\UnlockRoom;
use Konventix\Community\Actions\UnmuteParticipant;
use Konventix\Community\Data\MuteData;
use Konventix\Community\Enums\Permission;
use Konventix\Community\Enums\ReportState;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Message;
use Konventix\Community\Models\MessageReport;
use Konventix\Community\Models\Room;
use Konventix\Community\Providers\CommunityServiceProvider;
use Konventix\Community\Rooms\ModerationDesk;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console: one event's shoutbox desk (design §8, ADR-0029) — the
 * open report queue, the mute list, the lock switch and the latest lines.
 * Console copy, so English (ADR-0001).
 *
 * **Capability, not admission** (D-COM-04, spec §6). Every method opens
 * with `Gate::authorize(ModerateCommunity)` and nothing else about the
 * person: an organizer at a desk who was never scanned in must still be
 * able to act, so nothing here asks `ActiveEventContext` and nothing reads
 * `community_event_presence` (ADR-0067 decision 2).
 *
 * **The admin host resolves no brand** (ADR-0029), so the module gate
 * cannot be route middleware and every read escapes `BrandScope`. The brand
 * is the room's own: `community_rooms` is unique on the subject, so an
 * event id names at most one room, and `ModuleGate::isActiveForBrand()`
 * with that room's brand is what makes a brand with `community` off find no
 * desk (ADR-0003) — `AgendaAdminController`'s division. The room's brand
 * must also belong to this admin host's operator (`ensureReachable()`).
 *
 * **The console never creates a room.** It knows the event only by the id
 * in its URL — Community keeps no event read model — so it has no brand to
 * create one under. An event whose wall nobody has opened yet renders an
 * empty desk, and the writes 404 until a participant's first read creates
 * the room (spec §4.1).
 *
 * `{event}` is a plain ULID string: this module may not import Event's
 * model for implicit binding (ADR-0004).
 */
final class ModerationController
{
    public function show(string $event, ModerationDesk $desk): Response
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $room = $this->find($event);

        if ($room !== null) {
            $this->ensureReachable($room);
        }

        return Inertia::render('admin/community/Shoutbox', [
            'desk' => $desk->for($event, $room),
        ]);
    }

    /**
     * Readable, not writable, and reversible (spec §6). The reason is the
     * desk's own note; participants read `ShoutboxWriteException::roomLocked()`.
     */
    public function lock(Request $request, string $event, LockRoom $lockRoom): RedirectResponse
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $room = $this->room($event);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:'.LockRoom::MAX_REASON_LENGTH],
        ]);

        $reason = $validated['reason'] ?? null;

        $lockRoom->handle($room, $this->moderatorId($request), is_string($reason) ? $reason : null);

        return $this->done($event, 'Wall locked.');
    }

    public function unlock(string $event, UnlockRoom $unlockRoom): RedirectResponse
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $unlockRoom->handle($this->room($event));

        return $this->done($event, 'Wall unlocked.');
    }

    /**
     * A tombstone with a reason (spec §4.2); the room is told only that the
     * line is gone (`ShoutboxMessageRemoved::broadcastWith()`).
     */
    public function remove(Request $request, string $event, string $message, RemoveMessage $removeMessage): RedirectResponse
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $room = $this->room($event);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:'.RemoveMessage::MAX_REASON_LENGTH],
        ]);

        $line = Message::withoutBrandScope()
            ->forBrand($room->brand_id)
            ->where('room_id', $room->id)
            ->findOrFail($message);

        $removeMessage->handle($line, $this->moderatorId($request), (string) $validated['reason']);

        return $this->done($event, 'Line removed.');
    }

    /**
     * D-COM-08: timed, reason required, shown to the muted person. The input
     * is `MuteData::from($request)`, so the reason's `MAX_REASON_LENGTH` and
     * the minutes' bounds are enforced at the form — a 256-character reason
     * comes back as a field error, not as `MuteParticipant`'s exception.
     */
    public function mute(Request $request, string $event, string $user, MuteParticipant $muteParticipant): RedirectResponse
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $room = $this->room($event);
        $data = MuteData::from($request);

        $muteParticipant->handle($room, $user, $this->moderatorId($request), $data->reason, $data->minutes);

        return $this->done($event, 'Participant muted.');
    }

    public function unmute(string $event, string $user, UnmuteParticipant $unmuteParticipant): RedirectResponse
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $unmuteParticipant->handle($this->room($event), $user);

        return $this->done($event, 'Mute lifted.');
    }

    public function resolve(Request $request, string $event, string $report, ResolveReport $resolveReport): RedirectResponse
    {
        Gate::authorize(Permission::ModerateCommunity->value);

        $room = $this->room($event);

        $validated = $request->validate([
            'state' => ['required', Rule::in([ReportState::Resolved->value, ReportState::Dismissed->value])],
        ]);

        $state = ReportState::from((string) $validated['state']);

        $resolveReport->handle(
            MessageReport::withoutBrandScope()
                ->forBrand($room->brand_id)
                ->whereIn('message_id', Message::withoutBrandScope()
                    ->forBrand($room->brand_id)
                    ->where('room_id', $room->id)
                    ->select('id'))
                ->findOrFail($report),
            $this->moderatorId($request),
            $state,
        );

        return $this->done($event, $state === ReportState::Resolved ? 'Report resolved.' : 'Report dismissed.');
    }

    /**
     * The event's room, if a participant has opened the wall. At most one
     * row: `community_rooms` is unique on `(subject_type, subject_id)`.
     */
    private function find(string $event): ?Room
    {
        return Room::withoutBrandScope()
            ->where('subject_type', RoomSubject::Event)
            ->where('subject_id', $event)
            ->first();
    }

    /**
     * The room a write acts on, or a 404 — for an event with no room yet,
     * for one on another operator's brand, and for one whose brand has
     * `community` switched off.
     */
    private function room(string $event): Room
    {
        $room = $this->find($event) ?? abort(404);

        $this->ensureReachable($room);

        return $room;
    }

    /**
     * Two things the room lookup cannot say by itself, because it finds the
     * room by event id across every brand of the installation:
     *
     * 1. **the room's brand belongs to the operator whose admin host this
     *    is** (`OperatorContext`, set by `EnsureOperatorHost`) — the
     *    capability was checked against that operator's team, so without
     *    this a moderator of operator Y who knew an event id of operator X
     *    could read X's queue and act on X's wall. Platform's own consoles
     *    scope the same way (`BrandMenuAdminController`);
     * 2. `community` is active for that brand (ADR-0003) — the admin host
     *    resolves no brand for the route middleware to gate on.
     *
     * Both answer 404: a wall this desk may not see is a wall it does not
     * know about.
     */
    private function ensureReachable(Room $room): void
    {
        $operator = app(OperatorContext::class)->current() ?? abort(404);

        abort_unless(
            Brand::query()->whereKey($room->brand_id)->where('operator_id', $operator->getKey())->exists(),
            404,
        );

        abort_unless(
            app(ModuleGate::class)->isActiveForBrand(CommunityServiceProvider::moduleName(), $room->brand_id),
            404,
        );
    }

    private function moderatorId(Request $request): string
    {
        return (string) ($request->user() ?? abort(401))->getAuthIdentifier();
    }

    private function done(string $event, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.events.shoutbox.show', $event);
    }
}
