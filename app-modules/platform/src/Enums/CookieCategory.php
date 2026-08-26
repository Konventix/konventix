<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What a declared cookie needs from the visitor before it may be set.
 */
#[TypeScript]
enum CookieCategory: string
{
    /** § 25 Abs. 2 Nr. 2 TDDDG: technically required for the service the person asked for. No consent, no switch. */
    case Necessary = 'necessary';

    /** UI customisation the person switched on (WP194 §3.6): consented at the control, withdrawable in the dialog. */
    case Functional = 'functional';
}
