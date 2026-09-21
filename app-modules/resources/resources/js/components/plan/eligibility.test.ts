import { describe, expect, it } from 'vitest';
import {
    categoriesHaveFreePlace,
    firstPlaceIdsError,
    hintFor,
    isSelectable,
    placeReason,
    reconcileSelection,
    selectWithSwap,
    stateText,
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
    });

    it('offers a free place at the cap as a swap, which stays selectable and unhatched', () => {
        expect(
            placeReason({ ...base, state: 'free', selectedIds: ['p9'] }),
        ).toBe('swap');
        expect(
            placeReason({
                ...base,
                state: 'free',
                selectedIds: ['p8', 'p9'],
                quantity: 2,
            }),
        ).toBe('swap');
        expect(isSelectable('swap')).toBe(true);
        // A taken or foreign place at the cap still names its own reason.
        expect(
            placeReason({ ...base, state: 'booked', selectedIds: ['p9'] }),
        ).toBe('taken');
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
        expect(hintFor('taken')).toBe('This place is already taken.');
        expect(hintFor('not-in-category')).toBe(
            'This place is not in your category.',
        );
        expect(hintFor('unavailable')).toBe('This place is not available.');
        expect(hintFor('free')).toBeNull();
        // A swap needs no hint: the click succeeds, the announcement says what changed.
        expect(hintFor('swap')).toBeNull();
        expect(hintFor('swap')).toBeNull();
    });

    it('has a short state word for every reason', () => {
        expect(stateText('free')).toBe('Free');
        expect(stateText('mine')).toBe('Yours');
        expect(stateText('selected')).toBe('Selected');
        expect(stateText('taken')).toBe('Taken');
        expect(stateText('not-in-category')).toBe('Not in your category');
        expect(stateText('unavailable')).toBe('Unavailable');
        expect(stateText('swap')).toBe('Free, swaps');
    });
});

describe('selectWithSwap', () => {
    it('replaces the one selected place when the cap is 1', () => {
        expect(selectWithSwap([], 'a', 1)).toEqual(['a']);
        expect(selectWithSwap(['a'], 'b', 1)).toEqual(['b']);
    });

    it('drops the oldest selection at a cap of 2 and appends the new one', () => {
        expect(selectWithSwap(['a'], 'b', 2)).toEqual(['a', 'b']);
        expect(selectWithSwap(['a', 'b'], 'c', 2)).toEqual(['b', 'c']);
        expect(selectWithSwap(['b', 'c'], 'd', 2)).toEqual(['c', 'd']);
    });

    it('deselects a place that is clicked again, at or under the cap', () => {
        expect(selectWithSwap(['a', 'b'], 'a', 2)).toEqual(['b']);
        expect(selectWithSwap(['a'], 'a', 2)).toEqual([]);
        expect(selectWithSwap(['a'], 'a', 1)).toEqual([]);
    });

    it('selects nothing at a cap of zero', () => {
        expect(selectWithSwap([], 'a', 0)).toEqual([]);
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

describe('categoriesHaveFreePlace', () => {
    const places = [
        { id: 'a', resourceTypeId: 'seat' },
        { id: 'b', resourceTypeId: 'seat' },
        { id: 'c', resourceTypeId: 'vip' },
    ];

    it('is true when a place of an eligible category is free', () => {
        expect(
            categoriesHaveFreePlace(
                places,
                { a: 'booked', b: 'free', c: 'free' },
                ['seat'],
            ),
        ).toBe(true);
    });

    it("is true when a place of an eligible category is the registrant's own", () => {
        expect(
            categoriesHaveFreePlace(places, { a: 'mine', b: 'booked' }, [
                'seat',
            ]),
        ).toBe(true);
    });

    it('is false once every place of the eligible categories is taken, even if another category is free', () => {
        expect(
            categoriesHaveFreePlace(
                places,
                { a: 'booked', b: 'held', c: 'free' },
                ['seat'],
            ),
        ).toBe(false);
    });

    it('is false with no places at all', () => {
        expect(categoriesHaveFreePlace([], {}, ['seat'])).toBe(false);
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
