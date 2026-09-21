<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Why an assertion was refused or waived (spec §4.3/§4.4). Bounded codes,
 * never free text about a person: this column is read back years later, is
 * exported to the subject under Art. 15, and an operator's own sentence about
 * somebody's student ID has no business surviving in it.
 *
 * `OperatorWaived` is the code a waive carries, so a waived row says why it
 * exists without a second column.
 */
#[TypeScript]
enum EntryConditionReasonCode: string
{
    case DocumentMissing = 'document_missing';

    case DocumentInvalid = 'document_invalid';

    case OperatorWaived = 'operator_waived';
}
