<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What has become of one declaration (spec §4.3/§4.4).
 *
 * `Asserted` is what the registrant wrote; the other three are what somebody
 * later decided about it, at the console or at the door. A `Refused` row
 * records a fact and nothing more — it never revokes an admission and never
 * reaches Commerce (T-8, ADR-0005).
 *
 * "Not asserted" is deliberately **not** a case here: a condition added to a
 * live type after a registration exists writes no row at all, and the console
 * derives that state from the absence (D-REG-19, spec §4.2).
 */
#[TypeScript]
enum EntryConditionAssertionState: string
{
    case Asserted = 'asserted';

    case Verified = 'verified';

    case Refused = 'refused';

    case Waived = 'waived';
}
