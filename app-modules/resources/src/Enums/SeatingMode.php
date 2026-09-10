<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Resources' copy of Registration's seating mode (design §4.6): the value
 * arrives as a scalar in `RegistrationConfirmed`, and the *value* is the
 * contract, not the class (ADR-0004). `Pick` — the registrant chooses in the
 * picker; `Assign` — the organiser seats them. There is no `none`: a type
 * without seating simply carries no seating grant.
 */
#[TypeScript]
enum SeatingMode: string
{
    case Pick = 'pick';
    case Assign = 'assign';
}
