<?php

declare(strict_types=1);

namespace Konventix\Event\Enums;

/**
 * The capability catalogue this module owns (ADR-0013, SPEC.md
 * "Capabilities"). This is the source of truth for what Event can grant;
 * role → permission assignment is per-brand data, not code.
 *
 * Not wired to `permissions:sync`, Policies, or Actions yet — see TODO.md.
 */
enum Permission: string
{
    case ViewEvents = 'event.view-events';
    case CreateEvent = 'event.create-event';
    case UpdateEvent = 'event.update-event';
    case PublishEvent = 'event.publish-event';
    case RescheduleEvent = 'event.reschedule-event';
    case CancelEvent = 'event.cancel-event';
    case ArchiveEvent = 'event.archive-event';
    case ViewVenues = 'event.view-venues';
    case ManageVenues = 'event.manage-venues';
}
