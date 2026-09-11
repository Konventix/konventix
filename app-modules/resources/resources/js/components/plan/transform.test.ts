import { describe, expect, it } from 'vitest';
import {
    ABSOLUTE_MIN_ZOOM,
    fitToBbox,
    minZoomFor,
    panFrom,
    pinch,
    svgToWorld,
    worldToSvg,
    worldTransform,
    zoomAt,
} from './transform';

const viewport = { width: 10000, height: 6000 };

describe('worldTransform', () => {
    it('is the identity at zoom 1 with the pan on the viewport centre', () => {
        expect(
            worldTransform({ panX: 5000, panY: 3000, zoom: 1 }, viewport),
        ).toBe('translate(0 0) scale(1)');
    });

    it('agrees with worldToSvg and svgToWorld in both directions', () => {
        const view = { panX: 1234, panY: 567, zoom: 2.5 };
        const world = { x: 4321, y: 765 };
        const svg = worldToSvg(world, view, viewport);

        expect(svg).toEqual({
            x: (4321 - 1234) * 2.5 + 5000,
            y: (765 - 567) * 2.5 + 3000,
        });
        expect(svgToWorld(svg, view, viewport)).toEqual(world);
    });
});

describe('panFrom', () => {
    it('moves the pan against the pointer, divided by the zoom captured at pointer-down', () => {
        // LanCore EditorCanvas.vue:421-467: the delta is taken in raw SVG
        // coordinates and divided by a zoom snapshot, never re-read from
        // the view being mutated — that feedback loop is the jitter bug.
        const pan = panFrom(
            { x: 100, y: 100 },
            { x: 160, y: 70 },
            { x: 1000, y: 1000 },
            2,
        );

        expect(pan).toEqual({ x: 970, y: 1015 });
    });
});

describe('zoomAt', () => {
    it('keeps the world point under the cursor fixed on screen', () => {
        const view = { panX: 5000, panY: 3000, zoom: 1 };
        const anchor = { x: 8000, y: 1000 };
        const before = worldToSvg(anchor, view, viewport);
        const zoomed = zoomAt(view, anchor, 1.1, ABSOLUTE_MIN_ZOOM);
        const after = worldToSvg(anchor, zoomed, viewport);

        expect(zoomed.zoom).toBeCloseTo(1.1);
        expect(after.x).toBeCloseTo(before.x);
        expect(after.y).toBeCloseTo(before.y);
    });

    it('returns the same view when the clamp swallows the step', () => {
        const view = { panX: 5000, panY: 3000, zoom: 0.5 };

        expect(zoomAt(view, { x: 0, y: 0 }, 0.9, 0.5)).toBe(view);
    });
});

describe('minZoomFor and fitToBbox', () => {
    const bbox = { minX: 0, minY: 0, maxX: 20000, maxY: 6000 };

    it('derives the minimum zoom from the content extent, capped at 1', () => {
        // 20000 mm wide with 1.2 padding into a 10000-unit viewport.
        expect(minZoomFor(bbox, viewport)).toBeCloseTo(10000 / 24000);
        expect(
            minZoomFor({ minX: 0, minY: 0, maxX: 10, maxY: 10 }, viewport),
        ).toBe(1);
        expect(minZoomFor(null, viewport)).toBe(ABSOLUTE_MIN_ZOOM);
    });

    it('centres the bbox and fits it with padding', () => {
        const view = fitToBbox(bbox, viewport);

        expect(view.panX).toBe(10000);
        expect(view.panY).toBe(3000);
        expect(view.zoom).toBeCloseTo(10000 / 24000);
    });
});

describe('pinch', () => {
    it('scales by the finger distance ratio and keeps the start midpoint under the new midpoint', () => {
        const start = { panX: 5000, panY: 3000, zoom: 1 };
        const a0 = { x: 4000, y: 3000 };
        const b0 = { x: 6000, y: 3000 };
        const a1 = { x: 3500, y: 3200 };
        const b1 = { x: 7500, y: 3200 };
        const worldAtStartMid = svgToWorld(
            { x: 5000, y: 3000 },
            start,
            viewport,
        );

        const view = pinch(start, a0, b0, a1, b1, viewport, ABSOLUTE_MIN_ZOOM);
        const nowMid = worldToSvg(worldAtStartMid, view, viewport);

        expect(view.zoom).toBeCloseTo(2);
        expect(nowMid.x).toBeCloseTo(5500);
        expect(nowMid.y).toBeCloseTo(3200);
    });

    it('does nothing when the fingers started on the same point', () => {
        const start = { panX: 1, panY: 2, zoom: 3 };

        expect(
            pinch(
                start,
                { x: 1, y: 1 },
                { x: 1, y: 1 },
                { x: 0, y: 0 },
                { x: 9, y: 9 },
                viewport,
                0.05,
            ),
        ).toBe(start);
    });
});
