<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One catalog token, as shown in the theme editor's color-picker grid
 * (ADR-0008). `light`/`dark` are TokenCatalog's hardcoded defaults, not the
 * brand's current value — the editor overlays the brand's override on top.
 */
#[TypeScript]
final class ThemeTokenDefinitionData extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public string $light,
        public string $dark,
    ) {}
}
