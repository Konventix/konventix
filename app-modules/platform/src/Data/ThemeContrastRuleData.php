<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Theming\TokenCatalog;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The BFSG rule an imported theme file has to clear (ADR-0008), written into
 * the file itself: the minimum ratio, the pairs it is measured on, and one
 * sentence saying that it refuses rather than warns.
 *
 * The pairs alone were not enough. A file that lists which tokens are checked
 * against which, but not the threshold and not that a failure is fatal, still
 * leaves an author guessing why their first upload came back as a 422.
 */
#[TypeScript]
final class ThemeContrastRuleData extends Data
{
    /**
     * @param  array<int, ThemeContrastPairData>  $pairs
     */
    public function __construct(
        public float $minimumRatio,
        public string $note,
        #[DataCollectionOf(ThemeContrastPairData::class)]
        public array $pairs,
    ) {}

    public static function fromCatalog(): self
    {
        return new self(
            minimumRatio: TokenCatalog::MIN_CONTRAST_RATIO,
            note: implode(' ', [
                'Every pair below is measured on the effective values in "tokens" and "darkTokens" —',
                'WCAG relative luminance, the same maths the editor shows live.',
                '"text" is the foreground token, "surface" the token it is rendered on.',
                'Light and dark are judged separately, each against its own map.',
                'An import that misses the minimum on any pair it touches is refused whole,',
                'not warned about, and the stored theme stays as it was.',
            ]),
            pairs: array_map(
                static fn (array $pair): ThemeContrastPairData => new ThemeContrastPairData(...$pair),
                TokenCatalog::contrastPairs(),
            ),
        );
    }
}
