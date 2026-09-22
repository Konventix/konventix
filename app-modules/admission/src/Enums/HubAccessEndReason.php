<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * Why a participant's hub access ended (design §4.1). `DayEnded` is the
 * once-per-day window closing (D-HUB-01) and is undone by the next day's
 * accepted scan; the other three end access for good unless a second
 * valid admission of the same person is scanned.
 */
enum HubAccessEndReason: string
{
    case DayEnded = 'day_ended';
    case Concluded = 'concluded';
    case Cancelled = 'cancelled';
    case Revoked = 'revoked';
}
