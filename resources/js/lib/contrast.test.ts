/* eslint-disable konventix/no-palette-colors -- raw color literals are the
   subject under test: this file pins the color-shape conversion itself. */
import { describe, expect, it } from 'vitest';
import { toHexColor } from './contrast';

/**
 * The theme editor's color swatches (<input type="color">) only accept
 * 6-digit hex, while the catalog defaults and hand-typed overrides may be
 * any shape the server accepts (hex, rgb(), hsl()). These tests pin the
 * conversion so a saved hsl() override still shows its real color after a
 * page reload instead of the black fallback.
 */
describe('toHexColor', () => {
    it('passes 6-digit hex through', () => {
        expect(toHexColor('#1b2c3d')).toBe('#1b2c3d');
    });

    it('expands 3-digit hex', () => {
        expect(toHexColor('#fa0')).toBe('#ffaa00');
    });

    it('converts rgb()', () => {
        expect(toHexColor('rgb(255, 170, 0)')).toBe('#ffaa00');
    });

    it('converts hsl(), including the catalog default shape', () => {
        expect(toHexColor('hsl(0 0% 100%)')).toBe('#ffffff');
        expect(toHexColor('hsl(0 72% 42%)')).toBe('#b81e1e');
    });

    it('returns null for empty or unparseable values', () => {
        expect(toHexColor('')).toBeNull();
        expect(toHexColor('not-a-color')).toBeNull();
        expect(toHexColor('var(--accent)')).toBeNull();
    });
});
