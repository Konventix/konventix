import { describe, expect, it } from 'vitest';
import type { DraftElement, PlanDocument } from './document';
import { newElement } from './document';
import { checkInvariants } from './invariants';

/**
 * The client mirror of `PlanInvariants::check()` (design §6.1 step 2): the
 * same seven rules, keyed exactly like the server's 422 so the validation
 * panel can show both in one list.
 */
const CATEGORY = '01HZZZZZZZZZZZZZZZZZZZZZZ1';
const CATEGORIES = { [CATEGORY]: 'unit' as const };

function documentOf(...elements: DraftElement[]): PlanDocument {
    return { schema: 'konventix.plan/v1', elements } as PlanDocument;
}

function place(
    id: string,
    overrides: Partial<DraftElement> = {},
): DraftElement {
    return newElement('place', id, {
        resourceTypeId: CATEGORY,
        ref: id,
        ...overrides,
    });
}

describe('checkInvariants', () => {
    it('accepts a well-formed document', () => {
        const group = newElement('group', 'g1', { label: 'A' });

        expect(
            checkInvariants(
                documentOf(group, place('p1', { parentId: 'g1' })),
                CATEGORIES,
            ),
        ).toEqual([]);
    });

    it('reports a duplicate id on the second occurrence', () => {
        const issues = checkInvariants(
            documentOf(place('p1'), place('p1', { ref: 'x' })),
            CATEGORIES,
        );

        expect(issues).toHaveLength(1);
        expect(issues[0]).toMatchObject({
            index: 1,
            field: 'id',
            key: 'document.elements.1.id',
        });
    });

    it('reports a missing parent', () => {
        const issues = checkInvariants(
            documentOf(place('p1', { parentId: 'nope' })),
            CATEGORIES,
        );

        expect(issues[0]).toMatchObject({
            field: 'parentId',
            key: 'document.elements.0.parentId',
        });
    });

    it('reports a forbidden parent kind', () => {
        const issues = checkInvariants(
            documentOf(
                place('p1'),
                newElement('zone', 'z1', { parentId: 'p1' }),
            ),
            CATEGORIES,
        );

        expect(issues[0]).toMatchObject({ index: 1, field: 'parentId' });
    });

    it('reports a cycle without looping forever', () => {
        const a = newElement('group', 'a', { parentId: 'b' });
        const b = newElement('group', 'b', { parentId: 'a' });

        const issues = checkInvariants(documentOf(a, b), CATEGORIES);

        expect(issues.map((issue) => issue.field)).toEqual([
            'parentId',
            'parentId',
        ]);
    });

    it('reports a duplicate ref on the second occurrence', () => {
        const issues = checkInvariants(
            documentOf(
                place('p1', { ref: 'A-01' }),
                place('p2', { ref: 'A-01' }),
            ),
            CATEGORIES,
        );

        expect(issues).toEqual([
            expect.objectContaining({
                index: 1,
                field: 'ref',
                key: 'document.elements.1.ref',
            }),
        ]);
    });

    it('reports a place without a category and one with a foreign category', () => {
        const issues = checkInvariants(
            documentOf(
                place('p1', { resourceTypeId: null }),
                place('p2', { resourceTypeId: 'other' }),
            ),
            CATEGORIES,
        );

        expect(issues.map((issue) => issue.key)).toEqual([
            'document.elements.0.resourceTypeId',
            'document.elements.1.resourceTypeId',
        ]);
    });

    it('reports a place whose category is not unit mode', () => {
        const issues = checkInvariants(documentOf(place('p1')), {
            [CATEGORY]: 'quantity',
        });

        expect(issues).toEqual([
            expect.objectContaining({
                field: 'resourceTypeId',
                key: 'document.elements.0.resourceTypeId',
            }),
        ]);
    });

    it('reports a polygon with fewer than three points', () => {
        const fixture = newElement('fixture', 'f1', {
            shape: {
                type: 'polygon',
                w: null,
                h: null,
                radius: null,
                rx: null,
                ry: null,
                points: [
                    [0, 0],
                    [10, 0],
                ],
            },
        });

        expect(
            checkInvariants(documentOf(fixture), CATEGORIES)[0],
        ).toMatchObject({
            field: 'shape.points',
            key: 'document.elements.0.shape.points',
        });
    });
});
