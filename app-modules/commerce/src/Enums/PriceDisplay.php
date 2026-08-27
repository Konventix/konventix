<?php

declare(strict_types=1);

namespace Konventix\Commerce\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The net-vs-gross *display* decision for a shop product (ADR-0047). Same
 * shape as Registration's own `PriceDisplay`, deliberately not imported: a
 * module never imports another module's enum, only its own copy of the
 * contract (ADR-0004) — Registration's declares the flag for a registration
 * type, this one for a product.
 */
#[TypeScript]
enum PriceDisplay: string
{
    case Net = 'net';
    case Gross = 'gross';
}
