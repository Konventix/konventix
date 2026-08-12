<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Theming\TokenCatalog;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The theme editor's read-only reference data: the overridable token catalog
 * plus the contrast pairs it must satisfy (ADR-0008). Never hand-built with
 * an inline `->map(fn ...)` in the controller (ADR-0009) — ThemeAdminController
 * calls fromCatalog(), which is the single place TokenCatalog's plain arrays
 * become a typed, TypeScript-generated shape.
 */
#[TypeScript]
final class ThemeCatalogData extends Data
{
    /**
     * @param  array<int, ThemeTokenGroupData>  $groups
     * @param  array<int, ThemeContrastPairData>  $contrastPairs
     */
    public function __construct(
        #[DataCollectionOf(ThemeTokenGroupData::class)]
        public array $groups,
        #[DataCollectionOf(ThemeContrastPairData::class)]
        public array $contrastPairs,
    ) {}

    public static function fromCatalog(): self
    {
        return new self(
            groups: array_map(
                static fn (array $group): ThemeTokenGroupData => new ThemeTokenGroupData(
                    key: $group['key'],
                    label: $group['label'],
                    tokens: array_map(
                        static fn (array $token): ThemeTokenDefinitionData => new ThemeTokenDefinitionData(...$token),
                        $group['tokens'],
                    ),
                ),
                TokenCatalog::groups(),
            ),
            contrastPairs: array_map(
                static fn (array $pair): ThemeContrastPairData => new ThemeContrastPairData(...$pair),
                TokenCatalog::contrastPairs(),
            ),
        );
    }
}
