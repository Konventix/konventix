<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Room;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Platform\Modules\ModuleGate;

/**
 * A closed event closes its room (design §7): `closed_at` is set to the
 * instant the event concluded **or was cancelled**, and that instant is what
 * starts the 30-day clock `community:sweep-shoutbox` runs on. A closed room
 * refuses every post (`PostMessage`) and stays readable until the sweep;
 * the row itself is never deleted.
 *
 * **Both ways an event ends, not only the one the spec names.** Spec §7
 * says `EventConcluded`, but a running event can also be cancelled — and
 * then archived — without ever concluding, and a room that never closes is
 * a wall whose messages, mutes and reports are kept forever. Admission's
 * `EndHubAccessOnEventClosed` already treats the two as the same ending;
 * this follows its shape, one handler per event.
 *
 * The `Konventix\Event\Events\` imports are the sanctioned exception:
 * events are the public contract between modules (ADR-0004). Registered
 * explicitly in `CommunityServiceProvider::registerListeners()` — discovery
 * is off and must stay off (`.ai/rules/listeners.md`).
 *
 * **Gated on the event's own brand** (ADR-0003): with `community` off
 * nothing here runs. `ModuleGate::isActiveForBrand()` rather than the
 * ambient check, because concluding or cancelling is an operator's act on
 * the admin host, where no brand is resolved.
 *
 * **Idempotent.** Only a room that is still open is touched, so a replayed
 * lifecycle event — or a cancellation arriving after a conclusion — cannot
 * move the clock and restart the 30 days. An event whose wall nobody ever
 * opened has no room — rooms are created on first read (spec §4.1) — and
 * there is nothing to close.
 *
 * Synchronous: both events are raised inside their Action's transaction,
 * so this one-row write commits or rolls back with the closing itself.
 */
final class CloseRoomOnEventClosed
{
    public function handleConcluded(EventConcluded $event): void
    {
        $this->close($event->brandId, $event->eventId, $event->concludedAt);
    }

    public function handleCancelled(EventCancelled $event): void
    {
        $this->close($event->brandId, $event->eventId, $event->cancelledAt);
    }

    private function close(string $brandId, string $eventId, string $closedAt): void
    {
        if (! app(ModuleGate::class)->isActiveForBrand('community', $brandId)) {
            return;
        }

        $room = Room::withoutBrandScope()
            ->forBrand($brandId)
            ->where('subject_type', RoomSubject::Event)
            ->where('subject_id', $eventId)
            ->whereNull('closed_at')
            ->first();

        if ($room === null) {
            return;
        }

        // `closed_at` is off `$fillable` on purpose — it starts the
        // retention clock — so it is written through `forceFill`, and from
        // the event's own instant rather than this listener's clock.
        $room->forceFill(['closed_at' => CarbonImmutable::parse($closedAt)->utc()])->save();
    }
}
