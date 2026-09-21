<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Illuminate\Validation\Rule;
use Konventix\Platform\Models\Brand;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A brand theme as a file (ADR-0008): the two token maps under a schema
 * marker, so an export from one brand — or one instance — can be imported
 * into another. `format` is validated here, the way `PlanDocumentData`
 * validates its `schema`; what the maps contain (known token names, colour
 * values, BFSG contrast) stays `UpdateBrandTheme`'s business, reached
 * through `toThemeData()` so an import is refused exactly like a save.
 *
 * `exportedAt` and `brand` are provenance only: a file written by hand
 * may leave them out, and an import never reads them.
 */
#[TypeScript]
final class BrandThemeExportData extends Data
{
    public const string FORMAT = 'konventix.brand-theme/v1';

    /**
     * @param  array<string, string>  $tokens  Raw token name (no leading `--`) => CSS color, for light mode.
     * @param  array<string, string>  $darkTokens  The same, for `.dark`.
     */
    public function __construct(
        public string $format,
        public array $tokens,
        public array $darkTokens = [],
        public ?string $exportedAt = null,
        public ?string $brand = null,
    ) {}

    public static function ofBrand(Brand $brand): self
    {
        return new self(
            format: self::FORMAT,
            tokens: $brand->themeTokens(),
            darkTokens: $brand->darkThemeTokens(),
            exportedAt: now()->toIso8601String(),
            brand: $brand->slug,
        );
    }

    /**
     * The maps as the editor's own save form, validated by its rules — the
     * one path into `UpdateBrandTheme`.
     */
    public function toThemeData(): BrandThemeData
    {
        return BrandThemeData::validateAndCreate([
            'tokens' => $this->tokens,
            'darkTokens' => $this->darkTokens,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in([self::FORMAT])],
            'tokens' => ['present', 'array'],
            'tokens.*' => ['required', 'string', 'max:64'],
            'darkTokens' => ['sometimes', 'array'],
            'darkTokens.*' => ['required', 'string', 'max:64'],
            'exportedAt' => ['nullable', 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'format.required' => 'The file is not a '.self::FORMAT.' theme export.',
            'format.in' => 'The file is not a '.self::FORMAT.' theme export.',
        ];
    }
}
