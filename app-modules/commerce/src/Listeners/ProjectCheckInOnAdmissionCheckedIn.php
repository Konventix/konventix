<?php

declare(strict_types=1);

namespace Konventix\Commerce\Listeners;

use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Commerce\Actions\ProjectCheckedInAdmission;

/**
 * Feeds Commerce's check-in read model (ADR-0037, D-COM-15). Registered in
 * `CommerceServiceProvider::registerListeners()`.
 *
 * `Konventix\Admission\Events\AdmissionCheckedIn` is imported from the
 * `Events\` sub-namespace — the one sanctioned cross-module dependency
 * (ADR-0004). This listener is the *entire* reason Commerce can answer "was
 * this registration used?" without touching Admission's tables, which
 * D-COM-15 forbids outright.
 *
 * Only the `in` direction counts. A check-out means the person was inside,
 * which the matching check-in already recorded; treating it as a second
 * signal would add nothing and would double-handle every exit.
 */
final class ProjectCheckInOnAdmissionCheckedIn
{
    public function __construct(
        private readonly ProjectCheckedInAdmission $action,
    ) {}

    public function handle(AdmissionCheckedIn $event): void
    {
        if ($event->direction !== 'in') {
            return;
        }

        $this->action->handle(
            admissionId: $event->admissionId,
            registrationId: $event->registrationId,
            brandId: $event->brandId,
            scannedAt: $event->scannedAt,
        );
    }
}
