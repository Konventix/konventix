<?php

declare(strict_types=1);

namespace Konventix\Event\Enums;

/**
 * The event's lifecycle state (SPEC.md "Event lifecycle").
 *
 * Transitions are modelled as Actions in `src/Actions/`, each guarding which
 * states it may legally start from. `Cancelled` is a first-class transition
 * with a reason code, not a soft delete.
 *
 * `Announced` and `Running` are part of the catalogue per SPEC.md but have no
 * dedicated transition Action yet — see TODO.md follow-ups.
 */
enum EventLifecycleState: string
{
    case Draft = 'draft';
    case Announced = 'announced';
    case Published = 'published';
    case Running = 'running';
    case Concluded = 'concluded';
    case Archived = 'archived';
    case Cancelled = 'cancelled';
}
