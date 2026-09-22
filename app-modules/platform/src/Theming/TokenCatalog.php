<?php

declare(strict_types=1);

namespace Konventix\Platform\Theming;

/**
 * The overridable raw design tokens (ADR-0008's layer 1: `:root`/`.dark`
 * custom properties in resources/css/app.css, before `@theme inline` turns
 * them into Tailwind utilities). Grouped for the theme editor UI.
 *
 * Defaults below are HARDCODED copies of resources/css/app.css's `:root`
 * (light) and `.dark` values at the time of writing. There is no runtime
 * source of truth this reads from — CSS custom properties aren't
 * introspectable from PHP — so keep the two in sync BY HAND whenever
 * app.css's raw layer changes.
 *
 * Both sets are used for contrast validation: a brand's light overrides are
 * judged against `lightDefaults()` and its dark overrides against
 * `darkDefaults()`. Judging a dark palette by the light defaults would call a
 * correct dark accent a failure and let a real dark failure through.
 *
 * Both sets pass every `contrastPairs()` entry on their own, and a test holds
 * them to it. Dark `danger-contrast` on `danger` sat at 3.62:1 until slice 15b
 * — white text on a bright red — and now matches what `success` and `warning`
 * already did in dark mode: dark text on a bright fill (4.74:1).
 */
final class TokenCatalog
{
    /**
     * The BFSG contrast minimum every `contrastPairs()` entry must reach
     * (ADR-0008). It lives here rather than inside `UpdateBrandTheme` because
     * the theme export writes it into the file it hands an author, and the
     * number the file states has to be the number the save enforces.
     */
    public const float MIN_CONTRAST_RATIO = 4.5;

    private function __construct() {}

    /**
     * Text/contrast-color tokens paired with the surface or fill token they
     * render on. Every pair must reach the BFSG 4.5:1 contrast minimum
     * (ADR-0008) once merged with the light defaults below.
     *
     * @return array<int, array{text: string, surface: string}>
     */
    public static function contrastPairs(): array
    {
        return [
            ['text' => 'text', 'surface' => 'surface'],
            ['text' => 'text-muted', 'surface' => 'surface'],
            ['text' => 'on-accent', 'surface' => 'accent'],
            ['text' => 'danger-contrast', 'surface' => 'danger'],
            ['text' => 'success-contrast', 'surface' => 'success'],
            ['text' => 'warning-contrast', 'surface' => 'warning'],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, tokens: array<int, array{name: string, label: string, light: string, dark: string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'surface',
                'label' => 'Surfaces',
                'tokens' => [
                    ['name' => 'surface', 'label' => 'Surface', 'light' => 'hsl(0 0% 100%)', 'dark' => 'hsl(0 0% 3.9%)'],
                    ['name' => 'surface-raised', 'label' => 'Surface (raised)', 'light' => 'hsl(0 0% 100%)', 'dark' => 'hsl(0 0% 3.9%)'],
                    ['name' => 'surface-muted', 'label' => 'Surface (muted)', 'light' => 'hsl(0 0% 96.1%)', 'dark' => 'hsl(0 0% 16.08%)'],
                    ['name' => 'surface-inverted', 'label' => 'Surface (inverted)', 'light' => 'hsl(0 0% 9%)', 'dark' => 'hsl(0 0% 98%)'],
                ],
            ],
            [
                'key' => 'text',
                'label' => 'Text',
                'tokens' => [
                    ['name' => 'text', 'label' => 'Text', 'light' => 'hsl(0 0% 3.9%)', 'dark' => 'hsl(0 0% 98%)'],
                    ['name' => 'text-muted', 'label' => 'Text (muted)', 'light' => 'hsl(0 0% 45.1%)', 'dark' => 'hsl(0 0% 63.9%)'],
                    ['name' => 'text-inverted', 'label' => 'Text (inverted)', 'light' => 'hsl(0 0% 98%)', 'dark' => 'hsl(0 0% 9%)'],
                ],
            ],
            [
                'key' => 'brand',
                'label' => 'Brand',
                'tokens' => [
                    ['name' => 'accent', 'label' => 'Accent', 'light' => 'hsl(0 0% 9%)', 'dark' => 'hsl(0 0% 98%)'],
                    ['name' => 'on-accent', 'label' => 'On accent', 'light' => 'hsl(0 0% 98%)', 'dark' => 'hsl(0 0% 9%)'],
                ],
            ],
            [
                'key' => 'lines',
                'label' => 'Lines & fields',
                'tokens' => [
                    ['name' => 'border', 'label' => 'Border', 'light' => 'hsl(0 0% 92.8%)', 'dark' => 'hsl(0 0% 14.9%)'],
                    ['name' => 'input', 'label' => 'Input', 'light' => 'hsl(0 0% 89.8%)', 'dark' => 'hsl(0 0% 14.9%)'],
                    ['name' => 'ring', 'label' => 'Focus ring', 'light' => 'hsl(0 0% 3.9%)', 'dark' => 'hsl(0 0% 83.1%)'],
                ],
            ],
            [
                'key' => 'status',
                'label' => 'Status',
                'tokens' => [
                    ['name' => 'danger', 'label' => 'Danger', 'light' => 'hsl(0 72% 42%)', 'dark' => 'hsl(0 84% 60%)'],
                    ['name' => 'danger-contrast', 'label' => 'Danger (contrast)', 'light' => 'hsl(0 0% 98%)', 'dark' => 'hsl(0 0% 9%)'],
                    ['name' => 'danger-surface', 'label' => 'Danger (surface)', 'light' => 'hsl(0 86% 97%)', 'dark' => 'hsl(0 60% 12%)'],
                    ['name' => 'success', 'label' => 'Success', 'light' => 'hsl(142 72% 26%)', 'dark' => 'hsl(142 60% 45%)'],
                    ['name' => 'success-contrast', 'label' => 'Success (contrast)', 'light' => 'hsl(0 0% 98%)', 'dark' => 'hsl(0 0% 9%)'],
                    ['name' => 'warning', 'label' => 'Warning', 'light' => 'hsl(30 92% 34%)', 'dark' => 'hsl(38 92% 55%)'],
                    ['name' => 'warning-contrast', 'label' => 'Warning (contrast)', 'light' => 'hsl(0 0% 98%)', 'dark' => 'hsl(0 0% 9%)'],
                ],
            ],
        ];
    }

    /**
     * All overridable token names, flattened across groups.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::lightDefaults());
    }

    /**
     * @return array<string, string>
     */
    public static function lightDefaults(): array
    {
        return self::defaults('light');
    }

    /**
     * @return array<string, string>
     */
    public static function darkDefaults(): array
    {
        return self::defaults('dark');
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(string $mode): array
    {
        $defaults = [];

        foreach (self::groups() as $group) {
            foreach ($group['tokens'] as $token) {
                $defaults[$token['name']] = $token[$mode];
            }
        }

        return $defaults;
    }
}
