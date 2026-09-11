<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * How a `GrantKind::Seating` grant is placed (seating design §7.1).
 *
 * `Pick`: the registrant chooses in "Meine Registrierung". `Assign`: an
 * operator places the registration (console or `resources:assign-place`).
 * There is no `none` — a type that grants no seat carries no seating grant.
 *
 * Resources keeps a copy of these values in its own enum: the string is the
 * contract (ADR-0004), not the class.
 */
enum SeatingMode: string
{
    case Pick = 'pick';
    case Assign = 'assign';
}
