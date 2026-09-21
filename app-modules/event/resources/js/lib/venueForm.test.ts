import { describe, expect, it } from 'vitest';
import { emptyPlace, moveImage, resolvePrimary, setImageAt } from './venueForm';

describe('moveImage', () => {
    it('moves a picture one place and leaves the rest in order', () => {
        expect(moveImage(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
        expect(moveImage(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
    });

    it('refuses a move off either end rather than wrapping', () => {
        expect(moveImage(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
        expect(moveImage(['a', 'b'], 1, 1)).toEqual(['a', 'b']);
        expect(moveImage(['a', 'b'], 5, 1)).toEqual(['a', 'b']);
    });
});

describe('setImageAt', () => {
    it('replaces the picture in a slot', () => {
        expect(setImageAt(['a', 'b'], 1, 'c')).toEqual(['a', 'c']);
    });

    it('appends when the slot is the empty one past the end', () => {
        expect(setImageAt(['a'], 1, 'b')).toEqual(['a', 'b']);
    });

    it('clears a slot when nothing is chosen', () => {
        expect(setImageAt(['a', 'b', 'c'], 1, null)).toEqual(['a', 'c']);
    });

    it('refuses the same picture twice', () => {
        expect(setImageAt(['a', 'b'], 0, 'b')).toEqual(['a', 'b']);
    });
});

describe('resolvePrimary', () => {
    it('keeps a primary that is still in the list', () => {
        expect(resolvePrimary(['a', 'b'], 'b')).toBe('b');
    });

    it('falls back to the first picture when the primary left the list', () => {
        expect(resolvePrimary(['a', 'b'], 'gone')).toBe('a');
        expect(resolvePrimary(['a', 'b'], null)).toBe('a');
    });

    it('answers null when there are no pictures at all', () => {
        expect(resolvePrimary([], 'a')).toBeNull();
    });
});

describe('emptyPlace', () => {
    it('is a blank row, not a shared one', () => {
        const first = emptyPlace();
        first.label = 'Lot A';

        expect(emptyPlace().label).toBe('');
        expect(emptyPlace().latitude).toBeNull();
    });
});
