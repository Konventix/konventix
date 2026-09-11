<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The colour a category renders in, as the *name* of a semantic design token
 * (ADR-0008, ADR-0060 point 3) — never a hex value. The picker resolves it
 * to `var(--color-<token>)`; a category's `symbol` carries the same
 * information for anyone who cannot see colour (ADR-0055).
 */
#[TypeScript]
enum CategoryToken: string
{
    case Chart1 = 'chart-1';
    case Chart2 = 'chart-2';
    case Chart3 = 'chart-3';
    case Chart4 = 'chart-4';
    case Chart5 = 'chart-5';
    case Accent = 'accent';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
}
