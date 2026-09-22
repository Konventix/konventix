<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Models\Brand;
use Konventix\Platform\Theming\TokenCatalog;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A brand theme as a file (ADR-0008) — the document the console download
 * hands out. `ImportBrandThemeData` is the other half: one Data object per
 * endpoint (ADR-0009), and the two differ on purpose, see below.
 *
 * ## What the file holds
 *
 * `tokens` and `darkTokens` are the brand's **effective** theme: every token
 * in TokenCatalog, the brand's override where it has one and the catalog's
 * default where it does not. A brand that has themed nothing therefore
 * exports the complete default theme rather than `{}`, which is the whole
 * point — the file is meant to be authorable by somebody who has never seen
 * the editor.
 *
 * `overrides` and `darkOverrides` name the tokens the brand set for itself.
 * `authoringGuide` is the reference block (labels, groups, defaults, the
 * contrast pairs the import enforces), kept out of the value maps so it reads
 * as documentation rather than as something to edit.
 *
 * ## Why the envelope became v2
 *
 * `tokens` kept its name but changed its meaning: in v1 it was the brand's
 * overrides, in v2 it is the effective theme with `overrides` saying which of
 * those the brand owns. Same key, different contract — which is exactly what
 * a version marker is for.
 *
 * The deciding case is the older reader, not the newer one. A Konventix
 * instance still on v1 that is handed a v2 file would take all ~19 values as
 * overrides and freeze that moment's defaults into the brand, silently and
 * permanently. Because it validates `format` against a fixed list, a v2
 * envelope makes it refuse the file with a message instead. Keeping the v1
 * marker would have bought nothing and cost exactly the failure `overrides`
 * was added to prevent.
 *
 * v1 stays importable (`ImportBrandThemeData` accepts both markers): a v1
 * file is an override-only file with no marker list, which is precisely the
 * "no markers means these are all mine" reading described below, so it needs
 * no translation layer.
 *
 * ## Why the markers are a list beside the maps, not a flag per token
 *
 * Both were open. A per-token marker would have meant a value object per
 * entry — `{"value": "...", "own": true}` — turning the two maps from
 * something a designer can read and edit into a nested structure, and the
 * maps are the part of the file people actually work in. A flat list of names
 * beside them keeps `tokens` a plain name-to-colour map, costs two lines, and
 * is trivially diffable. It is also the shape the brand already stores: the
 * keys of `theme_tokens`.
 *
 * The list is sorted, so two exports of one theme stay byte-identical
 * (ThemeCanonicalJson sorts object keys but not list entries, and the stored
 * override map is in whatever order it was last saved).
 *
 * `exportedAt` and `brand` are provenance only: a file written by hand may
 * leave them out, and an import never reads them.
 */
#[TypeScript]
final class BrandThemeExportData extends Data
{
    public const string FORMAT = 'konventix.brand-theme/v2';

    /**
     * The predecessor envelope, still accepted on import. Its `tokens` map
     * held the brand's overrides and nothing else.
     */
    public const string FORMAT_V1 = 'konventix.brand-theme/v1';

    /**
     * @param  array<string, string>  $tokens  Raw token name (no leading `--`) => CSS color, for light mode. Every catalog token is present.
     * @param  array<string, string>  $darkTokens  The same, for `.dark`.
     * @param  array<int, string>  $overrides  The light tokens the brand sets itself; the rest follow the platform default.
     * @param  array<int, string>  $darkOverrides  The same, for `.dark`.
     */
    public function __construct(
        public string $format,
        public array $tokens,
        public array $darkTokens,
        public array $overrides,
        public array $darkOverrides,
        public ThemeAuthoringGuideData $authoringGuide,
        public ?string $exportedAt = null,
        public ?string $brand = null,
    ) {}

    public static function ofBrand(Brand $brand): self
    {
        return new self(
            format: self::FORMAT,
            tokens: array_merge(TokenCatalog::lightDefaults(), $brand->themeTokens()),
            darkTokens: array_merge(TokenCatalog::darkDefaults(), $brand->darkThemeTokens()),
            overrides: self::sortedNames($brand->themeTokens()),
            darkOverrides: self::sortedNames($brand->darkThemeTokens()),
            authoringGuide: ThemeAuthoringGuideData::fromCatalog(),
            exportedAt: now()->toIso8601String(),
            brand: $brand->slug,
        );
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<int, string>
     */
    private static function sortedNames(array $overrides): array
    {
        $names = array_keys($overrides);

        sort($names, SORT_STRING);

        return $names;
    }
}
