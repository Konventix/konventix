import { describe, expect, it } from 'vitest';
import {
    firstPlaceIdsError,
    hintFor,
    isSelectable,
    placeReason,
    reconcileSelection,
    stateText,
    toggleSelection,
} from './eligibility';

const base = {
    placeId: 'p1',
    resourceTypeId: 'seat',
    claimTypeIds: ['seat'],
    selectedIds: [] as string[],
    quantity: 1,
};

describe('placeReason', () => {
    it('lets a free place of an eligible category be selected', () => {
        expect(placeReason({ ...base, state: 'free' })).toBe('free');
        expect(isSelectable('free')).toBe(true);
    });

    it('names the reason a place cannot be taken, most specific first', () => {
        expect(
            placeReason({ ...base, state: 'free', resourceTypeId: 'vip' }),
        ).toBe('not-in-category');
        expect(placeReason({ ...base, state: 'booked' })).toBe('taken');
        expect(placeReason({ ...base, state: 'held' })).toBe('taken');
        expect(placeReason({ ...base, state: 'unavailable' })).toBe(
            'unavailable',
        );
        expect(placeReason({ ...base, state: undefined })).toBe('unavailable');
        expect(
            placeReason({ ...base, state: 'free', selectedIds: ['p9'] }),
        ).toBe('limit-reached');
    });

    it("keeps a selected place and the registrant's own place selectable", () => {
        expect(
            placeReason({ ...base, state: 'free', selectedIds: ['p1'] }),
        ).toBe('selected');
        expect(placeReason({ ...base, state: 'mine' })).toBe('mine');
        expect(isSelectable('selected')).toBe(true);
        expect(isSelectable('mine')).toBe(true);
        expect(isSelectable('taken')).toBe(false);
    });
});

describe('hints and state text', () => {
    it('tells taken, wrong category and limit apart in words', () => {
        expect(hintFor('taken', 2)).toBe('This place is already taken.');
        expect(hintFor('not-in-category', 2)).toBe(
            'This place is not in your category.',
        );
        expect(hintFor('limit-reached', 2)).toBe(
            'You can choose 2 places. Unselect one first.',
        );
        expect(hintFor('limit-reached', 1)).toBe(
            'You can choose 1 place. Unselect it first.',
        );
        expect(hintFor('unavailable', 1)).toBe('This place is not available.');
        expect(hintFor('free', 1)).toBeNull();
    });

    it('has a short state word for every reason', () => {
        expect(stateText('free')).toBe('Free');
        expect(stateText('mine')).toBe('Yours');
        expect(stateText('selected')).toBe('Selected');
        expect(stateText('taken')).toBe('Taken');
        expect(stateText('not-in-category')).toBe('Not in your category');
        expect(stateText('unavailable')).toBe('Unavailable');
        expect(stateText('limit-reached')).toBe('Limit reached');
    });
});

describe('toggleSelection', () => {
    it('adds up to the quantity and removes on a second toggle', () => {
        expect(toggleSelection([], 'a', 2)).toEqual(['a']);
        expect(toggleSelection(['a'], 'b', 2)).toEqual(['a', 'b']);
        expect(toggleSelection(['a', 'b'], 'c', 2)).toEqual(['a', 'b']);
        expect(toggleSelection(['a', 'b'], 'a', 2)).toEqual(['b']);
    });
});

describe('reconcileSelection', () => {
    it('drops a selected place somebody else took meanwhile and reports it', () => {
        const result = reconcileSelection(['a', 'b'], {
            a: 'booked',
            b: 'free',
        });

        expect(result.selected).toEqual(['b']);
        expect(result.lost).toEqual(['a']);
    });

    it("keeps the registrant's own and free places", () => {
        expect(
            reconcileSelection(['a', 'b'], { a: 'mine', b: 'free' }),
        ).toEqual({ selected: ['a', 'b'], lost: [] });
    });
});

describe('firstPlaceIdsError', () => {
    it('finds the placeIds error whether Laravel keyed it on the field or an index', () => {
        expect(firstPlaceIdsError({ placeIds: 'Taken.' })).toBe('Taken.');
        expect(firstPlaceIdsError({ 'placeIds.0': 'Not a place.' })).toBe(
            'Not a place.',
        );
        expect(firstPlaceIdsError({ other: 'x' })).toBeUndefined();
    });
});
