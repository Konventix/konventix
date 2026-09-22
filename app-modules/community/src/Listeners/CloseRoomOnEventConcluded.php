<?php

declare(strict_types=1);

namespace Konventix\Community\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Community\Enums\RoomSubject;
use Konventix\Community\Models\Room;
use Konventix\Event\Events\EventConcluded;
use Konventix\Platform\Modules\ModuleGate;

/**
 * `EventConcluded` closes the event's room (design §7): `closed_at` is set
 * to the instant the event concluded, and that instant is what starts the
 * 30-day clock `community:sweep-shoutbox` runs on. A closed room refuses
 * every post (`PostMessage`) and stays readable until the sweep; the row
 * itself is never deleted.
 *
 * The `Konventix\Event\Events\` import is the sanctioned exception: events
 * are the public contract between modules (ADR-0004). Registered
 * explicitly in `CommunityServiceProvider::registerListeners()` — discovery
 * is off and must stay off (`.ai/rules/listeners.md`).
 *
 * **Gated on the event's own brand** (ADR-0003): with `community` off
 * nothing here runs. `ModuleGate::isActiveForBrand()` rather than the
 * ambient check, because `ConcludeEvent` is an operator's act on the admin
 * host, where no brand is resolved.
 *
 * **Idempotent.** Only a room that is still open is touched, so a replayed
 * `EventConcluded` cannot move the clock and restart the 30 days. An event
 * whose wall nobody ever opened has no room — rooms are created on first
 * read (spec §4.1) — and there is nothing to close: no room, no messages,
 * nothing to retain.
 *
 * Synchronous: `ConcludeEvent` raises the event inside its own
 * transaction, so this one-row write commits or rolls back with the
 * conclusion itself. A room left open because the conclusion rolled back is
 * the right outcome — the event did not conclude.
 */
final class CloseRoomOnEventConcluded
{
    public function handle(EventConcluded $event): void
    {
        if (! app(ModuleGate::class)->isActiveForBrand('community', $event->brandId)) {
            return;
        }

        $room = Room::withoutBrandScope()
            ->forBrand($event->brandId)
            ->where('subject_type', RoomSubject::Event)
            ->where('subject_id', $event->eventId)
            ->whereNull('closed_at')
            ->first();

        if ($room === null) {
            return;
        }

        // `closed_at` is off `$fillable` on purpose — it starts the
        // retention clock — so it is written through `forceFill`, and from
        // the event's own instant rather than this listener's clock.
        $room->forceFill(['closed_at' => CarbonImmutable::parse($event->concludedAt)->utc()])->save();
    }
}
