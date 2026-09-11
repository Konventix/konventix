import { describe, expect, it } from 'vitest';
import { snapDelta, snapPoint, snapValue } from './snap';

/**
 * Snapping is `round(v / step) * step` (design §5), and a drag snaps the
 * *destination* of the anchor element, so a place already on the grid
 * lands on the grid again whatever the pointer did in between.
 */
describe('snapValue', () => {
    it('rounds to the nearest multiple of the step', () => {
        expect(snapValue(149, 100)).toBe(100);
        expect(snapValue(150, 100)).toBe(200);
        expect(snapValue(-149, 100)).toBe(-100);
        expect(snapValue(7, 10)).toBe(10);
        expect(snapValue(7, 1)).toBe(7);
    });
});

describe('snapPoint', () => {
    it('snaps both axes', () => {
        expect(snapPoint({ x: 1234, y: 5678 }, 1000)).toEqual({
            x: 1000,
            y: 6000,
        });
    });
});

describe('snapDelta', () => {
    it('keeps an on-grid anchor on the grid', () => {
        expect(snapDelta({ x: 800, y: 0 }, { x: 349, y: 351 }, 100)).toEqual({
            x: 300,
            y: 400,
        });
    });

    it('pulls an off-grid anchor onto the grid', () => {
        expect(snapDelta({ x: 830, y: 0 }, { x: 0, y: 0 }, 100)).toEqual({
            x: -30,
            y: 0,
        });
    });
});
