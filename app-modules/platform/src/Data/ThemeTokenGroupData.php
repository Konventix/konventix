<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A group of catalog tokens rendered together in the theme editor (surface,
 * text, brand, lines, status — TokenCatalog::groups()).
 */
#[TypeScript]
final class ThemeTokenGroupData extends Data
{
    /**
     * @param  array<int, ThemeTokenDefinitionData>  $tokens
     */
    public function __construct(
        public string $key,
        public string $label,
        #[DataCollectionOf(ThemeTokenDefinitionData::class)]
        public array $tokens,
    ) {}
}
