<?php

declare(strict_types=1);

namespace Konventix\Platform\Theming;

use InvalidArgumentException;

/**
 * Pure WCAG relative-luminance contrast math (ADR-0008's BFSG check). No
 * Laravel dependencies on purpose — every method here is a plain function
 * over strings and numbers, unit-testable without booting the framework.
 *
 * Understands the three color shapes the theme editor accepts: `#rgb`/
 * `#rrggbb` hex, `rgb()`/`rgba()`, and `hsl()`/`hsla()` (with or without
 * commas, matching both legacy and modern CSS Color syntax).
 */
final class ContrastRatio
{
    private function __construct() {}

    /**
     * Whether a string parses as one of the recognized CSS color shapes.
     */
    public static function isColor(string $value): bool
    {
        return self::parseColor($value) !== null;
    }

    /**
     * WCAG contrast ratio between two CSS colors, from 1:1 (identical) to
     * 21:1 (black on white). Throws when either value cannot be parsed —
     * callers validate colors with isColor() first when a graceful failure
     * is needed instead of an exception.
     */
    public static function ratio(string $a, string $b): float
    {
        $colorA = self::parseColor($a);
        $colorB = self::parseColor($b);

        if ($colorA === null || $colorB === null) {
            throw new InvalidArgumentException('Both values must be parseable CSS colors (hex, rgb(), or hsl()).');
        }

        $luminanceA = self::relativeLuminance($colorA['r'], $colorA['g'], $colorA['b']);
        $luminanceB = self::relativeLuminance($colorB['r'], $colorB['g'], $colorB['b']);

        $lighter = max($luminanceA, $luminanceB);
        $darker = min($luminanceA, $luminanceB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * WCAG 2.x relative luminance of an sRGB color (0-255 channels).
     */
    public static function relativeLuminance(int $r, int $g, int $b): float
    {
        $red = self::linearize($r);
        $green = self::linearize($g);
        $blue = self::linearize($b);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * @return array{r: int, g: int, b: int}|null
     */
    public static function parseColor(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $matches) === 1) {
            return self::parseHex($matches[1]);
        }

        if (preg_match('/^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)(?:[\s,\/]+[0-9.]+%?)?\s*\)$/i', $value, $matches) === 1) {
            return [
                'r' => self::clampChannel((float) $matches[1]),
                'g' => self::clampChannel((float) $matches[2]),
                'b' => self::clampChannel((float) $matches[3]),
            ];
        }

        if (preg_match('/^hsla?\(\s*([0-9.]+)(?:deg)?[\s,]+([0-9.]+)%[\s,]+([0-9.]+)%(?:[\s,\/]+[0-9.]+%?)?\s*\)$/i', $value, $matches) === 1) {
            return self::hslToRgb((float) $matches[1], (float) $matches[2], (float) $matches[3]);
        }

        return null;
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    private static function parseHex(string $hex): array
    {
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            'r' => (int) hexdec(substr($hex, 0, 2)),
            'g' => (int) hexdec(substr($hex, 2, 2)),
            'b' => (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @return array{r: int, g: int, b: int}
     */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360) / 360;

        if ($h < 0) {
            $h += 1;
        }

        $s /= 100;
        $l /= 100;

        if ($s === 0.0) {
            $red = $green = $blue = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;

            $red = self::hueToChannel($p, $q, $h + 1 / 3);
            $green = self::hueToChannel($p, $q, $h);
            $blue = self::hueToChannel($p, $q, $h - 1 / 3);
        }

        return [
            'r' => self::clampChannel($red * 255),
            'g' => self::clampChannel($green * 255),
            'b' => self::clampChannel($blue * 255),
        ];
    }

    private static function hueToChannel(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }

        if ($t > 1) {
            $t -= 1;
        }

        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }

        if ($t < 1 / 2) {
            return $q;
        }

        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }

    private static function clampChannel(float $value): int
    {
        return (int) round(min(255, max(0, $value)));
    }

    private static function linearize(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
}
