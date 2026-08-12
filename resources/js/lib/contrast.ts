/**
 * Client-side mirror of app-modules/platform/src/Theming/ContrastRatio.php
 * (ADR-0008's BFSG check). Used by the theme editor to warn before saving;
 * the server re-checks and is authoritative — this file exists so the
 * operator doesn't have to submit-and-fail to find a bad pair. Keep the two
 * implementations in sync by hand.
 */

export type Rgb = { r: number; g: number; b: number };

export const MIN_CONTRAST_RATIO = 4.5;

const HEX_RE = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i;
const RGB_RE =
    /^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)(?:[\s,/]+[0-9.]+%?)?\s*\)$/i;
const HSL_RE =
    /^hsla?\(\s*([0-9.]+)(?:deg)?[\s,]+([0-9.]+)%[\s,]+([0-9.]+)%(?:[\s,/]+[0-9.]+%?)?\s*\)$/i;

function clampChannel(value: number): number {
    return Math.round(Math.min(255, Math.max(0, value)));
}

function hueToChannel(p: number, q: number, t: number): number {
    let hue = t;

    if (hue < 0) {
        hue += 1;
    }

    if (hue > 1) {
        hue -= 1;
    }

    if (hue < 1 / 6) {
        return p + (q - p) * 6 * hue;
    }

    if (hue < 1 / 2) {
        return q;
    }

    if (hue < 2 / 3) {
        return p + (q - p) * (2 / 3 - hue) * 6;
    }

    return p;
}

function hslToRgb(h: number, s: number, l: number): Rgb {
    const hue = (((h % 360) + 360) % 360) / 360;
    const saturation = s / 100;
    const lightness = l / 100;

    if (saturation === 0) {
        const gray = clampChannel(lightness * 255);

        return { r: gray, g: gray, b: gray };
    }

    const q =
        lightness < 0.5
            ? lightness * (1 + saturation)
            : lightness + saturation - lightness * saturation;
    const p = 2 * lightness - q;

    return {
        r: clampChannel(hueToChannel(p, q, hue + 1 / 3) * 255),
        g: clampChannel(hueToChannel(p, q, hue) * 255),
        b: clampChannel(hueToChannel(p, q, hue - 1 / 3) * 255),
    };
}

export function parseColor(value: string): Rgb | null {
    const trimmed = value.trim();

    const hex = HEX_RE.exec(trimmed);

    if (hex) {
        let digits = hex[1];

        if (digits.length === 3) {
            digits = digits
                .split('')
                .map((c) => c + c)
                .join('');
        }

        return {
            r: parseInt(digits.slice(0, 2), 16),
            g: parseInt(digits.slice(2, 4), 16),
            b: parseInt(digits.slice(4, 6), 16),
        };
    }

    const rgb = RGB_RE.exec(trimmed);

    if (rgb) {
        return {
            r: clampChannel(parseFloat(rgb[1])),
            g: clampChannel(parseFloat(rgb[2])),
            b: clampChannel(parseFloat(rgb[3])),
        };
    }

    const hsl = HSL_RE.exec(trimmed);

    if (hsl) {
        return hslToRgb(parseFloat(hsl[1]), parseFloat(hsl[2]), parseFloat(hsl[3]));
    }

    return null;
}

export function isColor(value: string): boolean {
    return parseColor(value) !== null;
}

function linearize(channel: number): number {
    const c = channel / 255;

    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

export function relativeLuminance({ r, g, b }: Rgb): number {
    return 0.2126 * linearize(r) + 0.7152 * linearize(g) + 0.0722 * linearize(b);
}

/**
 * WCAG contrast ratio between two CSS colors, or null when either value
 * doesn't parse — callers decide how to render "unknown" vs. "fails".
 */
export function contrastRatio(a: string, b: string): number | null {
    const colorA = parseColor(a);
    const colorB = parseColor(b);

    if (!colorA || !colorB) {
        return null;
    }

    const luminanceA = relativeLuminance(colorA);
    const luminanceB = relativeLuminance(colorB);
    const lighter = Math.max(luminanceA, luminanceB);
    const darker = Math.min(luminanceA, luminanceB);

    return (lighter + 0.05) / (darker + 0.05);
}
