<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

/**
 * The declared tax class arriving in a settlement request's lines. String
 * values are the cross-module contract with Registration's declaration
 * (ADR-0017) — Commerce deliberately has its own enum rather than importing
 * Registration's, because events carry scalars and the string is the
 * contract (ADR-0004).
 */
enum TaxClass: string
{
    case Standard = 'standard';
    case Reduced = 'reduced';
}
