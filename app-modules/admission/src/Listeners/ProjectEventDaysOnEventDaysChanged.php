<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\ProjectEntranceEventProfile;
use Konventix\Event\Events\EventDaysChanged;

/**
 * Keeps the event-day half of `EntranceEventProfile` current (D-ADM-04).
 * Registered in `AdmissionServiceProvider::registerListeners()`.
 *
 * `Konventix\Event\Events\EventDaysChanged` is an `Events\` import, the one
 * sanctioned cross-module dependency (ADR-0004). Everything past this line
 * sees scalars only.
 */
final class ProjectEventDaysOnEventDaysChanged
{
    public function __construct(
        private readonly ProjectEntranceEventProfile $action,
    ) {}

    public function handle(EventDaysChanged $event): void
    {
        $this->action->days(
            eventId: $event->eventId,
            brandId: $event->brandId,
            days: $event->days,
        );
    }
}
