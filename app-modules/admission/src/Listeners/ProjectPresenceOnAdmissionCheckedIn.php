<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Events\AdmissionCheckedIn;

/**
 * `AdmissionCheckedIn` is emitted for accepted scans only (RecordScan's
 * reject path has its own event), so every one of these is a person
 * actually let in — or, with direction `out`, let out by the desk.
 * Registered in `AdmissionServiceProvider::registerListeners()` (D-HUB-01).
 *
 * **Queued, after commit.** The event fires inside `RecordScan`'s
 * transaction, and the door must never wait on or fail over the hub
 * (ADR-0033): a synchronous projection that hit the presence table's
 * unique key — two lanes scanning the same holder within a second — would
 * roll back an *accepted* scan. `$afterCommit` also drops the job with a
 * rolled-back scan, so a presence row can never outlive the check-in that
 * caused it. Constructor injection is the action only; no scoped binding
 * belongs in a listener constructor (`.ai/rules/listeners.md`).
 */
final class ProjectPresenceOnAdmissionCheckedIn implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(private readonly ProjectEventPresence $presence) {}

    public function handle(AdmissionCheckedIn $event): void
    {
        $this->presence->checkIn(
            brandId: $event->brandId,
            eventId: $event->eventId,
            admissionId: $event->admissionId,
            direction: $event->direction,
            scannedAt: CarbonImmutable::parse($event->scannedAt),
        );
    }
}
