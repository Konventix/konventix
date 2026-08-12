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
     * @param  array<string, string>  $tokens  Raw token name (no leading `--`) => CSS color. A token absent from the map falls back to the platform default.
     */
    public function __construct(
        public array $tokens,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'tokens' => ['present', 'array'],
            'tokens.*' => ['required', 'string', 'max:64'],
        ];
    }
}
