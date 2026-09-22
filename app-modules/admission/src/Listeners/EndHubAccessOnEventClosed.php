<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;

/**
 * A closed event has nobody inside it any more (design §4.2): every
 * presence row of the event loses access, `concluded` or `cancelled` by
 * which door it left through. No gate check — Admission is a Core module
 * and never off for a brand. Registered in
 * `AdmissionServiceProvider::registerListeners()` (D-HUB-01).
 *
 * **Queued, after commit**, the shape `ProjectPresenceOnAdmissionCheckedIn`
 * set: both events fire inside `ConcludeEvent`'s and `CancelEvent`'s
 * transactions, and a big event's sweep touches thousands of rows and
 * emits one `ParticipantHubAccessEnded` per row — work the admin's request
 * must neither wait on nor fail over, and which must not run at all when
 * the closing rolls back. Constructor injection is the action only
 * (`.ai/rules/listeners.md`).
 */
final class EndHubAccessOnEventClosed implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(private readonly ProjectEventPresence $presence) {}

    public function handleConcluded(EventConcluded $event): void
    {
        $this->presence->endForEvent($event->brandId, $event->eventId, HubAccessEndReason::Concluded, CarbonImmutable::parse($event->concludedAt));
    }

    public function handleCancelled(EventCancelled $event): void
    {
        $this->presence->endForEvent($event->brandId, $event->eventId, HubAccessEndReason::Cancelled, CarbonImmutable::parse($event->cancelledAt));
    }
}
