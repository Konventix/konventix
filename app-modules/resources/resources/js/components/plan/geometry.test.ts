import { describe, expect, it } from 'vitest';
import {
    absoluteBbox,
    absoluteOrigin,
    compareRefs,
    contentBbox,
    documentBbox,
    elementIndex,
    hitRadiusWorld,
    hitTest,
    hitTestPlaces,
    planTree,
    polygonPoints,
    shapeBbox,
    shapeCenter,
} from './geometry';

type Element = Konventix.Resources.Data.PlanElementData;
type Document = Konventix.Resources.Data.PlanDocumentData;

function element(
    overrides: Partial<Element> & Pick<Element, 'id' | 'kind'>,
): Element {
    return {
        parentId: null,
        ref: null,
        label: null,
        layer: 'default',
        x: 0,
        y: 0,
        rotation: 0,
        shape: {
            type: 'rect',
            w: 800,
            h: 800,
            radius: null,
            rx: null,
            ry: null,
            points: null,
        },
        locked: false,
        originId: null,
        token: null,
        layout: null,
        labelSchema: null,
        resourceTypeId: null,
        accessible: false,
        detached: false,
        blocked: false,
        fixtureType: null,
        text: null,
        fontSizeMm: null,
        ...overrides,
    };
}

function document(elements: Element[]): Document {
    return { schema: 'konventix.plan/v1', elements };
}

const zone = element({
    id: 'zone',
    kind: 'zone',
    label: 'Hall',
    x: 1000,
    y: 500,
    shape: {
        type: 'rect',
        w: 9000,
        h: 5000,
        radius: null,
        rx: null,
        ry: null,
        points: null,
    },
});
const group = element({
    id: 'group',
    kind: 'group',
    parentId: 'zone',
    label: 'Row A',
    x: 200,
    y: 300,
});
const placeA2 = element({
    id: 'a2',
    kind: 'place',
    parentId: 'group',
    ref: 'A-2',
    x: 900,
    y: 0,
    resourceTypeId: 'seat',
});
const placeA10 = element({
    id: 'a10',
    kind: 'place',
    parentId: 'group',
    ref: 'A-10',
    x: 1800,
    y: 0,
    resourceTypeId: 'seat',
});
const loose = element({
    id: 'loose',
    kind: 'place',
    ref: 'X-1',
    x: 50,
    y: 60,
    resourceTypeId: 'seat',
});

describe('absoluteOrigin', () => {
    it('sums the parent chain', () => {
        const index = elementIndex(document([zone, group, placeA2]));

        expect(absoluteOrigin(placeA2, index)).toEqual({ x: 2100, y: 800 });
    });

    it('survives a cycle instead of hanging', () => {
        const a = element({ id: 'a', kind: 'zone', parentId: 'b', x: 1, y: 1 });
        const b = element({ id: 'b', kind: 'zone', parentId: 'a', x: 1, y: 1 });
        const index = elementIndex(document([a, b]));

        expect(Number.isFinite(absoluteOrigin(a, index).x)).toBe(true);
    });

    /**
     * The backend's `Plans\WorldTransform::child()` composes rotation as it
     * walks the parent chain (`resource_plans` PHP side, matching design
     * §5's "rotation clockwise about the element's own origin"), because a
     * naive `x += parent.x` breaks the moment an ancestor is rotated —
     * `PlanNeighborsTest.php`'s "works in world coordinates, so a rotated
     * group keeps its neighbours" case proves the *neighbours* survive a
     * 90° group rotation, but that test would pass just as well with the
     * rotation direction inverted, since rotating a row of collinear points
     * preserves the distances between them either way. Pinning one concrete
     * world coordinate here is the test that would actually fail on a
     * flipped sign or a transposed axis.
     *
     * Mirrors PlanNeighborsTest.php's fixture exactly: a group at
     * (5000, 5000) rotated 90° clockwise, a place at local (800, 0). Hand
     * computation (rotating the local x-axis 90° clockwise in a y-down
     * frame turns "+x" into "+y"): world = (5000, 5000 + 800) = (5000, 5800).
     * A naive additive `absoluteOrigin` would instead answer (5800, 5000).
     */
    it('composes an ancestor rotation instead of just summing offsets', () => {
        const rotatedGroup = element({
            id: 'rg',
            kind: 'group',
            x: 5000,
            y: 5000,
            rotation: 90,
        });
        const place = element({
            id: 'rp',
            kind: 'place',
            parentId: 'rg',
            ref: 'A-01',
            x: 800,
            y: 0,
        });
        const index = elementIndex(document([rotatedGroup, place]));
        const origin = absoluteOrigin(place, index);

        expect(origin.x).toBeCloseTo(5000);
        expect(origin.y).toBeCloseTo(5800);
    });
});

describe('shapeBbox and shapeCenter', () => {
    const origin = { x: 100, y: 200 };

    it('treats rect x,y as the top-left corner', () => {
        expect(
            shapeBbox(
                {
                    type: 'rect',
                    w: 800,
                    h: 600,
                    radius: null,
                    rx: null,
                    ry: null,
                    points: null,
                },
                origin,
            ),
        ).toEqual({ minX: 100, minY: 200, maxX: 900, maxY: 800 });
    });

    it('treats ellipse and point x,y as the centre', () => {
        expect(
            shapeBbox(
                {
                    type: 'ellipse',
                    w: null,
                    h: null,
                    radius: null,
                    rx: 300,
                    ry: 100,
                    points: null,
                },
                origin,
            ),
        ).toEqual({ minX: -200, minY: 100, maxX: 400, maxY: 300 });
        expect(
            shapeBbox(
                {
                    type: 'point',
                    w: null,
                    h: null,
                    radius: null,
                    rx: null,
                    ry: null,
                    points: null,
                },
                origin,
            ),
        ).toEqual({ minX: -150, minY: -50, maxX: 350, maxY: 450 });
    });

    it('spans polygon points relative to the origin', () => {
        const shape = {
            type: 'polygon' as const,
            w: null,
            h: null,
            radius: null,
            rx: null,
            ry: null,
            points: [
                [0, 0],
                [400, 0],
                [200, 300],
            ],
        };

        expect(shapeBbox(shape, origin)).toEqual({
            minX: 100,
            minY: 200,
            maxX: 500,
            maxY: 500,
        });
        expect(shapeCenter(shape, origin)).toEqual({ x: 300, y: 350 });
        expect(polygonPoints(shape, origin)).toBe('100,200 500,200 300,500');
    });
});

describe('contentBbox', () => {
    it('is null for an empty document and the union otherwise', () => {
        expect(contentBbox(document([]))).toBeNull();
        expect(contentBbox(document([zone, group, placeA2]))).toEqual({
            minX: 1000,
            minY: 500,
            maxX: 10000,
            maxY: 5500,
        });
    });
});

describe('absoluteBbox and documentBbox', () => {
    it('boxes one element by id in absolute millimetres', () => {
        expect(absoluteBbox(document([zone, group, placeA2]), 'a2')).toEqual({
            minX: 2100,
            minY: 800,
            maxX: 2900,
            maxY: 1600,
        });
    });

    it('gives an empty box for an id the document does not hold, and mirrors contentBbox for the whole document', () => {
        expect(absoluteBbox(document([zone]), 'gone')).toEqual({
            minX: 0,
            minY: 0,
            maxX: 0,
            maxY: 0,
        });
        expect(documentBbox(document([]))).toBeNull();
        expect(documentBbox(document([zone, group, placeA2]))).toEqual(
            contentBbox(document([zone, group, placeA2])),
        );
    });
});

describe('hitTest', () => {
    it('returns the topmost element under the point and null over the floor', () => {
        const doc = document([zone, group, placeA2]);

        expect(hitTest(doc, { x: 2200, y: 900 })).toBe('a2');
        expect(hitTest(doc, { x: 9500, y: 5000 })).toBe('zone');
        expect(hitTest(doc, { x: -500, y: -500 })).toBeNull();
    });
});

describe('hitRadiusWorld', () => {
    it('grows as the plan is zoomed out so the target stays 44 px', () => {
        // 0.05 px per mm on screen at zoom 1: 22 px is 440 mm.
        expect(hitRadiusWorld(0.05, 1)).toBeCloseTo(440);
        expect(hitRadiusWorld(0.05, 2)).toBeCloseTo(220);
        expect(hitRadiusWorld(0, 1)).toBe(22);
    });
});

describe('planTree', () => {
    it('nests places under their group and zone, refs in natural order, loose places last', () => {
        const tree = planTree(
            document([placeA10, loose, zone, group, placeA2]),
        );

        expect(tree.map((z) => z.label)).toEqual(['Hall', 'Other places']);
        expect(tree[0].groups[0].label).toBe('Row A');
        expect(tree[0].groups[0].places.map((p) => p.ref)).toEqual([
            'A-2',
            'A-10',
        ]);
        expect(tree[1].groups[0].places.map((p) => p.ref)).toEqual(['X-1']);
    });

    it('sorts refs numerically', () => {
        expect(['A-10', 'A-2', 'A-1'].sort(compareRefs)).toEqual([
            'A-1',
            'A-2',
            'A-10',
        ]);
    });
});

describe('hitTestPlaces', () => {
    const box = (cx: number, cy: number, half: number) => ({
        minX: cx - half,
        minY: cy - half,
        maxX: cx + half,
        maxY: cy + half,
    });
    const first = {
        id: 'first',
        center: { x: 1000, y: 1000 },
        box: box(1000, 1000, 400),
    };
    const second = {
        id: 'second',
        center: { x: 1700, y: 1000 },
        box: box(1700, 1000, 400),
    };

    it('picks the nearest centre regardless of paint order', () => {
        // Both 44 px radii cover the point; the earlier-painted place is nearer.
        expect(
            hitTestPlaces([first, second], { x: 1200, y: 1000 }, 600)?.id,
        ).toBe('first');
        expect(
            hitTestPlaces([first, second], { x: 1500, y: 1000 }, 600)?.id,
        ).toBe('second');
    });

    it('returns null when the point is outside every radius and every box', () => {
        expect(
            hitTestPlaces([first, second], { x: 1000, y: 3000 }, 440),
        ).toBeNull();
        expect(hitTestPlaces([], { x: 1000, y: 1000 }, 440)).toBeNull();
    });

    it('hits a large place anywhere inside its box, even far from its centre', () => {
        const stage = {
            id: 'stage',
            center: { x: 5000, y: 5000 },
            box: box(5000, 5000, 3000),
        };

        expect(hitTestPlaces([stage], { x: 2200, y: 7800 }, 100)?.id).toBe(
            'stage',
        );
        expect(hitTestPlaces([stage], { x: 8200, y: 5000 }, 100)).toBeNull();
    });
});
