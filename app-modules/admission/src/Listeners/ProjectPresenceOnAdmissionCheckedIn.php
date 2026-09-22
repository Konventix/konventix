<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Carbon\CarbonImmutable;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Events\AdmissionCheckedIn;

/**
 * `AdmissionCheckedIn` is emitted for accepted scans only (RecordScan's
 * reject path has its own event), so every one of these is a person
 * actually let in — or, with direction `out`, let out by the desk.
 * Registered in `AdmissionServiceProvider::registerListeners()` (D-HUB-01).
 */
final class ProjectPresenceOnAdmissionCheckedIn
{
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
