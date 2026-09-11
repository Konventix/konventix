import { describe, expect, it } from 'vitest';
import type { PlanDocument } from './document';
import { newElement } from './document';
import { createPlanStore } from './usePlanStore';

/**
 * Design §8.3: `applyMutation(label, fn)` with same-label coalescing within
 * 500 ms, a 50-step undo ring, and `isDirty` against the last saved
 * document. The clock is injected so the coalescing window is testable.
 */
function documentOf(): PlanDocument {
    return {
        schema: 'konventix.plan/v1',
        elements: [
            newElement('group', 'g1', { label: 'A' }),
            newElement('place', 'p1', {
                parentId: 'g1',
                label: 'A-01',
                resourceTypeId: 'c1',
            }),
            newElement('place', 'p2', {
                parentId: 'g1',
                label: 'A-02',
                resourceTypeId: 'c1',
                locked: true,
            }),
        ],
    } as PlanDocument;
}

function storeWithClock() {
    const clock = { now: 0 };
    const store = createPlanStore(documentOf(), { now: () => clock.now });

    return { store, clock };
}

describe('createPlanStore', () => {
    it('starts clean and becomes dirty on the first mutation', () => {
        const { store } = storeWithClock();

        expect(store.isDirty.value).toBe(false);

        store.updateElement('p1', 'inspector:label', (element) => {
            element.label = 'A-1';
        });

        expect(store.isDirty.value).toBe(true);
        expect(store.document.value.elements[1].label).toBe('A-1');
    });

    it('coalesces same-label mutations inside 500 ms into one undo step', () => {
        const { store, clock } = storeWithClock();

        store.updateElement('p1', 'inspector:label', (element) => {
            element.label = 'B';
        });
        clock.now = 400;
        store.updateElement('p1', 'inspector:label', (element) => {
            element.label = 'BC';
        });

        store.undo();

        expect(store.document.value.elements[1].label).toBe('A-01');
        expect(store.canUndo.value).toBe(false);
    });

    it('starts a new step after the window or for another label', () => {
        const { store, clock } = storeWithClock();

        store.updateElement('p1', 'inspector:label', (element) => {
            element.label = 'B';
        });
        clock.now = 600;
        store.updateElement('p1', 'inspector:label', (element) => {
            element.label = 'BC';
        });
        clock.now = 700;
        store.updateElement('p1', 'inspector:ref', (element) => {
            element.ref = 'BC';
        });

        store.undo();
        expect(store.document.value.elements[1]).toMatchObject({
            label: 'BC',
            ref: null,
        });
        store.undo();
        expect(store.document.value.elements[1].label).toBe('B');
        store.undo();
        expect(store.document.value.elements[1].label).toBe('A-01');
    });

    it('redoes and drops the redo stack on a new mutation', () => {
        const { store, clock } = storeWithClock();

        store.updateElement('p1', 'a', (element) => {
            element.label = 'B';
        });
        store.undo();
        expect(store.canRedo.value).toBe(true);
        store.redo();
        expect(store.document.value.elements[1].label).toBe('B');

        store.undo();
        clock.now = 5000;
        store.updateElement('p1', 'b', (element) => {
            element.label = 'C';
        });

        expect(store.canRedo.value).toBe(false);
    });

    it('keeps at most 50 undo steps', () => {
        const { store, clock } = storeWithClock();

        for (let step = 1; step <= 60; step++) {
            clock.now = step * 1000;
            store.updateElement('p1', 'inspector:label', (element) => {
                element.label = `L${step}`;
            });
        }

        let undone = 0;

        while (store.canUndo.value) {
            store.undo();
            undone++;
        }

        expect(undone).toBe(50);
        expect(store.document.value.elements[1].label).toBe('L10');
    });

    it('is clean again after markSaved and dirty after the next edit', () => {
        const { store, clock } = storeWithClock();

        store.updateElement('p1', 'a', (element) => {
            element.label = 'B';
        });
        store.markSaved();
        expect(store.isDirty.value).toBe(false);

        clock.now = 1000;
        store.updateElement('p1', 'b', (element) => {
            element.label = 'C';
        });
        expect(store.isDirty.value).toBe(true);
    });

    it('marks the sent snapshot as saved, not edits made meanwhile', () => {
        const { store, clock } = storeWithClock();

        store.updateElement('p1', 'a', (element) => {
            element.label = 'B';
        });
        const sent = store.document.value;
        clock.now = 1000;
        store.updateElement('p1', 'b', (element) => {
            element.label = 'C';
        });

        store.markSaved(sent);

        expect(store.isDirty.value).toBe(true);
    });

    it('removes descendants with their parent and prunes the selection', () => {
        const { store } = storeWithClock();

        store.select(['g1', 'p1']);
        store.removeElements(['g1']);

        expect(store.document.value.elements).toEqual([]);
        expect(store.selection.value).toEqual([]);
    });

    it('moves the given elements but never a locked one', () => {
        const { store } = storeWithClock();

        store.moveElements(['p1', 'p2'], 100, -50);

        expect(store.document.value.elements[1]).toMatchObject({
            x: 100,
            y: -50,
        });
        expect(store.document.value.elements[2]).toMatchObject({ x: 0, y: 0 });
    });

    it('toggles membership in the selection', () => {
        const { store } = storeWithClock();

        store.select(['p1']);
        store.toggleSelected('p2');
        store.toggleSelected('p1');

        expect(store.selection.value).toEqual(['p2']);
        expect(
            store.selectedElements.value.map((element) => element.id),
        ).toEqual(['p2']);
    });
});
