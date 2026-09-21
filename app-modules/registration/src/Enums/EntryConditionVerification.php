<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How an entry condition is meant to be checked (D-REG-22, spec §4.1/§4.4).
 * Three kinds and no fourth — the catalogue is closed so the door, the
 * console and the ticket PDF all read the same vocabulary.
 *
 * `OperatorChecked` is **advisory**: it fills an operator worklist and never
 * gates confirmation. ADR-0005 already has an axis for "a human decides
 * before this is confirmed" — `approval: required` on the type — and a
 * second mechanism that silently gated confirmation would be two ways to say
 * one thing. The condition editor says so out loud.
 *
 * The value is a metric label (`entry_condition_assertions_total`), which is
 * exactly why it is a bounded enum and the condition's own `key` is not
 * (T-11).
 */
#[TypeScript]
enum EntryConditionVerification: string
{
    /** The declaration is the whole check; nobody asks again. */
    case SelfDeclared = 'self_declared';

    /** The door asks for the document named in `document_hint`. */
    case DocumentAtDoor = 'document_at_door';

    /** An operator looks at it before the event; advisory, never blocking. */
    case OperatorChecked = 'operator_checked';
}
