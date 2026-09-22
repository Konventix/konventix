<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Theming\TokenCatalog;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The reference block of a brand theme file (ADR-0008): prose, the contrast
 * rule, and one entry per catalog token saying what it is and what the
 * platform default for it is.
 *
 * It exists because the values alone are not authorable. A designer or a
 * coding agent handed `{"accent": "hsl(0 0% 9%)"}` cannot know which other
 * names exist, what `on-accent` is for, which pairs have to clear 4.5:1, or
 * that a bad value is refused rather than ignored — so their first file comes
 * back as a 422 citing a rule the file never stated.
 *
 * It is written by the export and *ignored* by the import
 * (`ImportBrandThemeData` does not declare it, so laravel-data drops it):
 * editing it changes nothing, and it is always regenerated from TokenCatalog,
 * so a file can never carry a stale copy of the rules into a later version of
 * the platform. It sorts first in the canonical document — "authoringGuide"
 * before "brand" — which is deliberate: the head of the file is the part a
 * reader needs first.
 */
#[TypeScript]
final class ThemeAuthoringGuideData extends Data
{
    /**
     * @param  array<int, string>  $aboutThisFile  Prose, one paragraph per entry, in reading order.
     * @param  array<string, ThemeTokenReferenceData>  $tokens  Keyed by raw token name, parallel to the file's own `tokens`/`darkTokens` maps.
     */
    public function __construct(
        public array $aboutThisFile,
        public ThemeContrastRuleData $contrastRule,
        #[DataCollectionOf(ThemeTokenReferenceData::class)]
        public array $tokens,
    ) {}

    public static function fromCatalog(): self
    {
        $tokens = [];

        foreach (TokenCatalog::groups() as $group) {
            foreach ($group['tokens'] as $token) {
                $tokens[$token['name']] = new ThemeTokenReferenceData(
                    label: $token['label'],
                    group: $group['key'],
                    groupLabel: $group['label'],
                    defaultLight: $token['light'],
                    defaultDark: $token['dark'],
                );
            }
        }

        return new self(
            aboutThisFile: self::aboutThisFile(),
            contrastRule: ThemeContrastRuleData::fromCatalog(),
            tokens: $tokens,
        );
    }

    /**
     * Everything an author needs that the values themselves do not say.
     * English copy, deliberately whole sentences rather than keywords: the
     * intended readers are a designer opening the file in an editor and a
     * coding agent given nothing but the file.
     *
     * @return array<int, string>
     */
    private static function aboutThisFile(): array
    {
        return [
            'This is a Konventix brand theme. Everything needed to write a new one is in this file: the current value of every token, the platform default behind it, what each token is for, and the accessibility rule an upload has to clear.',
            '"tokens" and "darkTokens" hold the effective value of every token in the catalog, for light mode and for dark mode. They are always complete — a brand that has themed nothing exports the platform defaults — so this file is a working theme as it stands.',
            'To make a new theme: change the values in "tokens" and "darkTokens", list the names you changed in "overrides" and "darkOverrides", and upload the file in the operator console under Brands, Theme, "Export and import".',
            '"overrides" and "darkOverrides" name the tokens this brand sets for itself. A token that is not named there follows the platform default and goes on following it when that default changes later. That is what the two lists are for: importing a file without them would silently freeze today\'s defaults into the brand.',
            'Leaving "overrides" or "darkOverrides" out of the file entirely means every value in the matching map is the brand\'s own. That is also what a konventix.brand-theme/v1 file means, and it is the sensible reading of a hand-written file that lists only the few values its author cares about.',
            'A token name is the raw CSS custom property without the leading dashes: "accent" is "--accent". Only the names listed under "tokens" in this guide exist; any other name is refused.',
            'A value is a CSS colour in one of three shapes: "#rgb" or "#rrggbb", "rgb(...)" or "rgba(...)", or "hsl(...)" or "hsla(...)", with or without commas. Anything else is refused.',
            'Light and dark are separate maps on purpose. A dark accent is supposed to look wrong beside the light palette, and each map is checked against the defaults of its own scheme — judging a dark palette by the light defaults calls a correct accent a failure and lets a real dark failure through.',
            'An import replaces both maps at once and is checked exactly like a save in the editor: an unknown token name, an unrecognised colour or a contrast failure refuses the whole file and leaves the brand\'s stored theme untouched.',
            '"authoringGuide", "brand" and "exportedAt" are written by the export and ignored by the import. Delete them if you like; editing them changes nothing. The guide is regenerated from the platform catalog on every export, so a newer export always states the current rules.',
        ];
    }
}
