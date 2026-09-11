<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a group generates its places (design §5): a row of `count` places, or
 * a `rows × cols` grid.
 */
#[TypeScript]
enum LayoutKind: string
{
    case Linear = 'linear';
    case Grid = 'grid';
}
