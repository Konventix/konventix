<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\BrandThemeData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Theming\ContrastRatio;
use Konventix\Platform\Theming\TokenCatalog;

/**
 * Saves a brand's raw design-token overrides (ADR-0008). Refuses the save —
 * does not warn — when a token name falls outside TokenCatalog, a value
 * isn't a recognized CSS color, or a text/surface pair fails the BFSG 4.5:1
 * contrast minimum once merged with the catalog's defaults. "Refuses, not
 * warns" is the ADR's own wording.
 *
 * Light and dark are validated separately, each against its own defaults.
 * That separation is the whole point of the dark map: measured against the
 * light defaults a correct dark accent looks like a failure, and a genuine
 * dark failure — near-black text on the dark surface — passes unnoticed.
 *
 * Capability: platform.manage-brand-theme (checked at the controller; the
 * full AuthorizingAction dispatcher of ADR-0015 is follow-up work, matching
 * CreateBrand's precedent).
 */
final class UpdateBrandTheme
{
    public function handle(Brand $brand, BrandThemeData $data): Brand
    {
        return DB::transaction(function () use ($brand, $data): Brand {
            $this->guardKnownTokenNames($data->tokens, 'tokens');
            $this->guardColorValues($data->tokens, 'tokens');
            $this->guardContrast($data->tokens, TokenCatalog::lightDefaults(), 'tokens', 'light');

            $this->guardKnownTokenNames($data->darkTokens, 'darkTokens');
            $this->guardColorValues($data->darkTokens, 'darkTokens');
            $this->guardContrast($data->darkTokens, TokenCatalog::darkDefaults(), 'darkTokens', 'dark');

            $brand->update([
                'theme_tokens' => $data->tokens,
                'theme_tokens_dark' => $data->darkTokens,
            ]);

            return $brand->refresh();
        });
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function guardKnownTokenNames(array $tokens, string $field): void
    {
        $unknown = array_diff(array_keys($tokens), TokenCatalog::names());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $field => __('Unknown token name(s): :names.', ['names' => implode(', ', $unknown)]),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function guardColorValues(array $tokens, string $field): void
    {
        foreach ($tokens as $name => $value) {
            if (! ContrastRatio::isColor($value)) {
                throw ValidationException::withMessages([
                    "{$field}.{$name}" => __("The value for ':name' is not a recognized CSS color (hex, rgb(), or hsl()).", ['name' => $name]),
                ]);
            }
        }
    }

    /**
     * Merges the submitted overrides onto the catalog's defaults for that
     * colour scheme, then checks each ADR-0008 pair the save actually touches
     * (either its text or its surface token is a key in $tokens) against the
     * merged, "effective" value — a brand overriding only `accent` still gets
     * checked against the platform's default `on-accent`, and vice versa.
     *
     * Pairs the save does not touch are still skipped. When this Action was
     * written that was load-bearing, because the platform's own defaults
     * failed three pairs and checking them unconditionally would have refused
     * every save. Those defaults now all pass in both schemes and a test holds
     * them there, so the skip has become a scoping choice rather than a
     * workaround: this Action refuses the combinations an operator is
     * choosing, and the platform's palette is held to account by its own test.
     *
     * @param  array<string, string>  $tokens
     * @param  array<string, string>  $defaults
     */
    private function guardContrast(array $tokens, array $defaults, string $field, string $scheme): void
    {
        $effective = array_merge($defaults, $tokens);
        $touched = array_keys($tokens);

        foreach (TokenCatalog::contrastPairs() as $pair) {
            if (! in_array($pair['text'], $touched, true) && ! in_array($pair['surface'], $touched, true)) {
                continue;
            }

            $ratio = ContrastRatio::ratio($effective[$pair['text']], $effective[$pair['surface']]);

            if ($ratio < TokenCatalog::MIN_CONTRAST_RATIO) {
                throw ValidationException::withMessages([
                    $field => __(
                        $scheme === 'dark'
                            ? "In dark mode, ':text' on ':surface' has a contrast ratio of :ratio:1, below the :minimum:1 BFSG minimum (ADR-0008)."
                            : "In light mode, ':text' on ':surface' has a contrast ratio of :ratio:1, below the :minimum:1 BFSG minimum (ADR-0008).",
                        [
                            'text' => $pair['text'],
                            'surface' => $pair['surface'],
                            'ratio' => sprintf('%.2f', $ratio),
                            'minimum' => sprintf('%.1f', TokenCatalog::MIN_CONTRAST_RATIO),
                        ],
                    ),
                ]);
            }
        }
    }
}
