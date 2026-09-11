<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A seat claim's lifecycle (design §4.6, §7.5). `Unplaced` — entitled, no
 * place yet; `Placed` — its bookings are confirmed; `Released` — the
 * registration was cancelled or rejected and the claim will not be placed
 * again. A plan conflict resolved with `release` sends a claim back to
 * `Unplaced`, not to `Released`.
 */
#[TypeScript]
enum ClaimState: string
{
    case Unplaced = 'unplaced';
    case Placed = 'placed';
    case Released = 'released';
}
