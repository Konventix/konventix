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
 * contrast minimum once merged with the catalog's light defaults. "Refuses,
 * not warns" is the ADR's own wording.
 *
 * Capability: platform.manage-brand-theme (checked at the controller; the
 * full AuthorizingAction dispatcher of ADR-0015 is follow-up work, matching
 * CreateBrand's precedent).
 */
final class UpdateBrandTheme
{
    private const float MIN_CONTRAST_RATIO = 4.5;

    public function handle(Brand $brand, BrandThemeData $data): Brand
    {
        return DB::transaction(function () use ($brand, $data): Brand {
            $this->guardKnownTokenNames($data->tokens);
            $this->guardColorValues($data->tokens);
            $this->guardContrast($data->tokens);

            $brand->update(['theme_tokens' => $data->tokens]);

            return $brand->refresh();
        });
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function guardKnownTokenNames(array $tokens): void
    {
        $unknown = array_diff(array_keys($tokens), TokenCatalog::names());

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'tokens' => sprintf('Unknown token name(s): %s.', implode(', ', $unknown)),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function guardColorValues(array $tokens): void
    {
        foreach ($tokens as $name => $value) {
            if (! ContrastRatio::isColor($value)) {
                throw ValidationException::withMessages([
                    "tokens.{$name}" => "The value for '{$name}' is not a recognized CSS color (hex, rgb(), or hsl()).",
                ]);
            }
        }
    }

    /**
     * Merges the submitted overrides onto the catalog's light defaults, then
     * checks each ADR-0008 pair the save actually touches (either its text or
     * its surface token is a key in $tokens) against the merged, "effective"
     * value — a brand overriding only `accent` still gets checked against the
     * platform's default `on-accent`, and vice versa.
     *
     * Pairs the save does not touch are skipped on purpose: TokenCatalog's
     * hardcoded light defaults for `danger-contrast`/`danger`,
     * `success-contrast`/`success` and `warning-contrast`/`warning` were
     * found, while building this Action, to already fall short of 4.5:1 (the
     * starter-kit palette was never accessibility-audited — see app.css's own
     * TODO comment). Checking untouched pairs unconditionally would refuse
     * every save, including ones that never go near those tokens. Fixing the
     * platform-wide defaults is tracked in TODO.md; this Action only refuses
     * combinations the operator is choosing.
     *
     * @param  array<string, string>  $tokens
     */
    private function guardContrast(array $tokens): void
    {
        $effective = array_merge(TokenCatalog::lightDefaults(), $tokens);
        $touched = array_keys($tokens);

        foreach (TokenCatalog::contrastPairs() as $pair) {
            if (! in_array($pair['text'], $touched, true) && ! in_array($pair['surface'], $touched, true)) {
                continue;
            }

            $ratio = ContrastRatio::ratio($effective[$pair['text']], $effective[$pair['surface']]);

            if ($ratio < self::MIN_CONTRAST_RATIO) {
                throw ValidationException::withMessages([
                    'tokens' => sprintf(
                        "'%s' on '%s' has a contrast ratio of %.2f:1, below the %.1f:1 BFSG minimum (ADR-0008).",
                        $pair['text'],
                        $pair['surface'],
                        $ratio,
                        self::MIN_CONTRAST_RATIO,
                    ),
                ]);
            }
        }
    }
}
