<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One door check a ticket still owes today (ADR-0066 §6.4, attendee-list
 * design §4.3), as `Support\OwedDoorChecks` finds it: the key the answer is
 * posted under, the label the check-in dialog shows (ADR-0066 §5 allows it on
 * the console) and the document hint. Never an assertion: the list carries
 * what the door would ask, not what Registration decided (ADR-0066,
 * Consequences).
 */
#[TypeScript]
final class DoorCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $documentHint,
    ) {}
}
