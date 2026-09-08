<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Which clock this operator's readers are on (#242) — the companion to
 * `DateFormat`, kept separate because the two choices are independent: an
 * operator selling into the United States may want MM/DD/YYYY on a 24-hour
 * clock, and nothing about either implies the other.
 */
#[TypeScript]
enum TimeFormat: string
{
    case TwentyFourHour = '24h';
    case TwelveHour = '12h';

    /**
     * The same pattern as `date()` understands it. `g:i A` rather than
     * `h:i A`: a leading zero on an am/pm hour is not how anybody writes one.
     */
    public function phpFormat(): string
    {
        return match ($this) {
            self::TwentyFourHour => 'H:i',
            self::TwelveHour => 'g:i A',
        };
    }
}
