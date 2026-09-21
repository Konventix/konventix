<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Where a decision on an assertion came from (spec §4.3/§4.4): an operator in
 * the console, or whoever was standing at the entrance.
 *
 * A metric label (`entry_condition_decisions_total{source}`), and the reason
 * `KonventixEntryConditionRefusalsAtDoor` can single out the door without
 * counting an operator's desk work.
 */
#[TypeScript]
enum EntryConditionDecisionSource: string
{
    case Console = 'console';

    case Door = 'door';
}
