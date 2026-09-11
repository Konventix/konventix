<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the picker knows about one place (design §7.4). `Mine` is a booking
 * of the viewer's own registration; `Held` and `Booked` are somebody else's
 * (never *who*); `Unavailable` is blocked or on an unpublished plan.
 */
#[TypeScript]
enum PlaceState: string
{
    case Free = 'free';
    case Mine = 'mine';
    case Held = 'held';
    case Booked = 'booked';
    case Unavailable = 'unavailable';
}
