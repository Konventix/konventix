<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A text/surface token pair the theme editor must keep at or above the BFSG
 * 4.5:1 contrast minimum (ADR-0008), mirrored client-side from
 * TokenCatalog::contrastPairs() so the UI warns with the same rule the
 * server enforces.
 */
#[TypeScript]
final class ThemeContrastPairData extends Data
{
    public function __construct(
        public string $text,
        public string $surface,
    ) {}
}
