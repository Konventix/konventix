<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The brand theme editor's save form (ADR-0009): one definition for
 * validation, Inertia props and TypeScript. Shape-only validation lives here
 * — token names against the catalog, values as colors, and BFSG contrast
 * (ADR-0008) are business rules checked by UpdateBrandTheme, not form rules.
 */
#[TypeScript]
final class BrandThemeData extends Data
{
    /**
     * @param  array<string, string>  $tokens  Raw token name (no leading `--`) => CSS color, for light mode. A token absent from the map falls back to the platform default.
     * @param  array<string, string>  $darkTokens  The same, for `.dark`. Kept as its own map rather than merged: a brand that themes only light mode must not accidentally darken, and each map is contrast-checked against its own defaults.
     */
    public function __construct(
        public array $tokens,
        public array $darkTokens = [],
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'tokens' => ['present', 'array'],
            'tokens.*' => ['required', 'string', 'max:64'],
            'darkTokens' => ['sometimes', 'array'],
            'darkTokens.*' => ['required', 'string', 'max:64'],
        ];
    }
}
