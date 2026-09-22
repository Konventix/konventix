<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An uploaded brand theme file (ADR-0008), validated on the way in — the
 * reading half of `BrandThemeExportData`, whose docblock carries the envelope
 * and override-marking reasoning.
 *
 * It is a second Data object rather than a second use of the export's
 * (ADR-0009: one per endpoint) because the two documents are not the same
 * shape. The export writes a reference block; the import must *ignore* it,
 * and anything else an author leaves in the file. Declaring only the keys
 * that mean something on the way in is what makes that true: laravel-data
 * maps the payload onto declared properties and drops the rest, so a stale,
 * partial or hand-mangled `authoringGuide` can never refuse a file whose
 * values are fine.
 *
 * Only the envelope is validated here — known token names, colour values and
 * BFSG contrast stay `UpdateBrandTheme`'s business, reached through
 * `toThemeData()` so an import is refused exactly where a save would be.
 */
#[TypeScript]
final class ImportBrandThemeData extends Data
{
    /**
     * @param  array<string, string>  $tokens  Raw token name (no leading `--`) => CSS color, for light mode.
     * @param  array<string, string>  $darkTokens  The same, for `.dark`.
     * @param  array<int, string>|null  $overrides  The light tokens the file claims as the brand's own. `null` means the key was absent — see `own()`.
     * @param  array<int, string>|null  $darkOverrides  The same, for `.dark`.
     */
    public function __construct(
        public string $format,
        public array $tokens,
        public array $darkTokens = [],
        public ?array $overrides = null,
        public ?array $darkOverrides = null,
    ) {}

    /**
     * The maps reduced to what the brand should actually store, as the
     * editor's own save form, validated by its rules — the one path into
     * `UpdateBrandTheme`.
     */
    public function toThemeData(): BrandThemeData
    {
        return BrandThemeData::validateAndCreate([
            'tokens' => $this->own($this->tokens, $this->overrides),
            'darkTokens' => $this->own($this->darkTokens, $this->darkOverrides),
        ]);
    }

    /**
     * The values the file claims as the brand's own, which is what gets
     * stored; everything else stays unset so the brand keeps following the
     * platform default and keeps following it when that default moves.
     *
     * A missing marker list is not an empty one. A v1 file has no list and
     * its map is overrides only; a hand-written file that lists the four
     * colours its author cares about and no markers means those four are
     * theirs. Both read as "all of them", which is why `$names` is nullable
     * and `[]` is honoured as the genuinely empty case an export of an
     * unthemed brand writes.
     *
     * A name listed without a value in the map is dropped rather than
     * refused: it says nothing about what the colour should be.
     *
     * @param  array<string, string>  $values
     * @param  array<int, string>|null  $names
     * @return array<string, string>
     */
    private function own(array $values, ?array $names): array
    {
        if ($names === null) {
            return $values;
        }

        return array_intersect_key($values, array_flip($names));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in([BrandThemeExportData::FORMAT, BrandThemeExportData::FORMAT_V1])],
            'tokens' => ['present', 'array'],
            'tokens.*' => ['required', 'string', 'max:64'],
            'darkTokens' => ['sometimes', 'array'],
            'darkTokens.*' => ['required', 'string', 'max:64'],
            'overrides' => ['sometimes', 'nullable', 'array'],
            'overrides.*' => ['required', 'string', 'max:64'],
            'darkOverrides' => ['sometimes', 'nullable', 'array'],
            'darkOverrides.*' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $formats = BrandThemeExportData::FORMAT.' or '.BrandThemeExportData::FORMAT_V1;

        return [
            'format.required' => 'The file is not a '.$formats.' theme export.',
            'format.in' => 'The file is not a '.$formats.' theme export.',
        ];
    }
}
