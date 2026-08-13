<?php

declare(strict_types=1);

namespace Konventix\Admission\Listeners;

use Konventix\Admission\Actions\ProjectEntranceEventProfile;
use Konventix\Event\Events\EventReentryPolicyChanged;

/**
 * Keeps the re-entry-policy half of `EntranceEventProfile` current
 * (D-ADM-03). Registered in `AdmissionServiceProvider::registerListeners()`.
 *
 * The door must decide re-entry with no server round trip (ADR-0033), and
 * Admission may not read Event's models (ADR-0004) — this projection is the
 * only path by which the policy reaches the entrance API at all.
 */
final class ProjectReentryPolicyOnEventReentryPolicyChanged
{
    public function __construct(
        private readonly ProjectEntranceEventProfile $action,
    ) {}

    public function handle(EventReentryPolicyChanged $event): void
    {
        $this->action->reentryPolicy(
            eventId: $event->eventId,
            brandId: $event->brandId,
            reentryPolicy: $event->reentryPolicy,
        );
    }
}
