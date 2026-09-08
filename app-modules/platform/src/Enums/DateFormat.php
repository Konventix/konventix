<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How this operator writes a calendar date (#242).
 *
 * The value is the pattern as an operator reads it on the settings page, not
 * a PHP format string: the same three values are handed to the browser as a
 * shared prop, where `resources/js/lib/datetime.ts` builds the same three
 * dates out of `Intl` parts. One vocabulary on both sides means the console
 * and the invoice cannot drift apart, which is the whole complaint behind
 * the issue.
 */
#[TypeScript]
enum DateFormat: string
{
    case DayMonthYear = 'DD.MM.YYYY';
    case MonthDayYear = 'MM/DD/YYYY';
    case YearMonthDay = 'YYYY-MM-DD';

    /**
     * The same pattern as `date()` understands it.
     */
    public function phpFormat(): string
    {
        return match ($this) {
            self::DayMonthYear => 'd.m.Y',
            self::MonthDayYear => 'm/d/Y',
            self::YearMonthDay => 'Y-m-d',
        };
    }
}
