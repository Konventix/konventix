import { describe, expect, it } from 'vitest';
import type { DraftElement, Layout } from './document';
import { newElement } from './document';
import { generatePlaces, layoutSlots, renderLabel } from './generator';

/**
 * Design §5: `{row}` is the group label, `{n}`/`{n:02}` the 1-based index
 * from `startIndex`, `{col}` the column in a grid. Regeneration counts the
 * non-detached places, appends or removes at the end and keeps ids.
 */
const CATEGORY = '01HZZZZZZZZZZZZZZZZZZZZZZ1';
const SHAPE = {
    type: 'rect',
    w: 800,
    h: 800,
    radius: null,
    rx: null,
    ry: null,
    points: null,
} as const;

function ids(): () => string {
    let counter = 0;

    return () => `new-${++counter}`;
}

function linear(count: number): Layout {
    return {
        type: 'linear',
        count,
        rows: null,
        cols: null,
        spacing: 800,
        rowSpacing: null,
        startIndex: 1,
    };
}

function seedFor(
    layout: Layout,
    existing: DraftElement[] = [],
    place = '{row}-{n:02}',
) {
    return {
        parentId: 'g1',
        groupLabel: 'A',
        layout,
        labelSchema: { row: '{row}', place },
        resourceTypeId: CATEGORY,
        shape: SHAPE,
        existing,
        newId: ids(),
    };
}

describe('renderLabel', () => {
    it('fills the four placeholders', () => {
        const vars = { row: 'B', n: 7, col: 3 };

        expect(renderLabel('{row}-{n:02}', vars)).toBe('B-07');
        expect(renderLabel('{row}{n}', vars)).toBe('B7');
        expect(renderLabel('{row}/{col}', vars)).toBe('B/3');
        expect(renderLabel('{n:03}', vars)).toBe('007');
    });
});

describe('layoutSlots', () => {
    it('lays a grid out row by row with its own row spacing', () => {
        expect(
            layoutSlots({
                type: 'grid',
                count: null,
                rows: 2,
                cols: 3,
                spacing: 800,
                rowSpacing: 1000,
                startIndex: 1,
            }),
        ).toEqual([
            { x: 0, y: 0, row: 1, col: 1 },
            { x: 800, y: 0, row: 1, col: 2 },
            { x: 1600, y: 0, row: 1, col: 3 },
            { x: 0, y: 1000, row: 2, col: 1 },
            { x: 800, y: 1000, row: 2, col: 2 },
            { x: 1600, y: 1000, row: 2, col: 3 },
        ]);
    });
});

describe('generatePlaces', () => {
    it('generates a linear row with schema labels and refs', () => {
        const places = generatePlaces(seedFor(linear(4)));

        expect(places.map((place) => place.label)).toEqual([
            'A-01',
            'A-02',
            'A-03',
            'A-04',
        ]);
        expect(places.map((place) => place.ref)).toEqual([
            'A-01',
            'A-02',
            'A-03',
            'A-04',
        ]);
        expect(places.map((place) => place.x)).toEqual([0, 800, 1600, 2400]);
        expect(
            places.every(
                (place) => place.kind === 'place' && place.parentId === 'g1',
            ),
        ).toBe(true);
        expect(places.every((place) => place.resourceTypeId === CATEGORY)).toBe(
            true,
        );
        expect(places.map((place) => place.id)).toEqual([
            'new-1',
            'new-2',
            'new-3',
            'new-4',
        ]);
    });

    it('starts counting at startIndex and honours {n} without padding', () => {
        const places = generatePlaces(
            seedFor({ ...linear(2), startIndex: 17 }, [], '{row}-{n}'),
        );

        expect(places.map((place) => place.label)).toEqual(['A-17', 'A-18']);
    });

    it('uses the column placeholder in a grid', () => {
        const grid: Layout = {
            type: 'grid',
            count: null,
            rows: 2,
            cols: 2,
            spacing: 800,
            rowSpacing: 800,
            startIndex: 1,
        };
        const places = generatePlaces({
            ...seedFor(grid, [], '{row}{n}/{col}'),
            labelSchema: { row: 'R{n}', place: '{row}-{col}' },
        });

        expect(places.map((place) => place.label)).toEqual([
            'R1-1',
            'R1-2',
            'R2-1',
            'R2-2',
        ]);
    });

    it('keeps existing ids and appends when the count grows', () => {
        const existing = generatePlaces(seedFor(linear(4)));
        const regenerated = generatePlaces(seedFor(linear(6), existing));

        expect(regenerated.map((place) => place.id)).toEqual([
            'new-1',
            'new-2',
            'new-3',
            'new-4',
            'new-1',
            'new-2',
        ]);
        expect(regenerated.map((place) => place.label)).toEqual([
            'A-01',
            'A-02',
            'A-03',
            'A-04',
            'A-05',
            'A-06',
        ]);
    });

    it('removes at the end when the count shrinks', () => {
        const existing = generatePlaces(seedFor(linear(4)));

        expect(
            generatePlaces(seedFor(linear(2), existing)).map(
                (place) => place.id,
            ),
        ).toEqual(['new-1', 'new-2']);
    });

    it('keeps a category an operator changed by hand', () => {
        const existing = generatePlaces(seedFor(linear(2)));
        existing[1].resourceTypeId = 'vip';

        expect(
            generatePlaces(seedFor(linear(2), existing))[1].resourceTypeId,
        ).toBe('vip');
    });

    it('ignores detached places and keeps them after the generated ones', () => {
        const detached = newElement('place', 'loose', {
            parentId: 'g1',
            detached: true,
            x: 5000,
            y: 5000,
            label: 'Loose',
        });
        const places = generatePlaces(seedFor(linear(2), [detached]));

        expect(places.map((place) => place.id)).toEqual([
            'new-1',
            'new-2',
            'loose',
        ]);
        expect(places[2]).toMatchObject({
            x: 5000,
            y: 5000,
            label: 'Loose',
            detached: true,
        });
    });
});
