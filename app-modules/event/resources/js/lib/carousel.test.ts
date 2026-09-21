import { describe, expect, it } from 'vitest';
import {
    clampSlide,
    nextSlide,
    previousSlide,
    slideForKey,
    slideStatus,
} from './carousel';

describe('nextSlide / previousSlide', () => {
    it('steps forward and wraps at the end', () => {
        expect(nextSlide(0, 3)).toBe(1);
        expect(nextSlide(1, 3)).toBe(2);
        expect(nextSlide(2, 3)).toBe(0);
    });

    it('steps back and wraps at the start', () => {
        expect(previousSlide(2, 3)).toBe(1);
        expect(previousSlide(0, 3)).toBe(2);
    });

    it('stays at zero for an empty or single-slide carousel', () => {
        expect(nextSlide(0, 0)).toBe(0);
        expect(previousSlide(0, 0)).toBe(0);
        expect(nextSlide(0, 1)).toBe(0);
        expect(previousSlide(0, 1)).toBe(0);
    });

    it('clamps an index that came in out of range', () => {
        expect(clampSlide(-4, 3)).toBe(0);
        expect(clampSlide(9, 3)).toBe(2);
        expect(clampSlide(1, 0)).toBe(0);
    });
});

describe('slideForKey', () => {
    it('maps the arrow keys onto a step with wrap-around', () => {
        expect(slideForKey('ArrowRight', 2, 3)).toBe(0);
        expect(slideForKey('ArrowLeft', 0, 3)).toBe(2);
    });

    it('jumps to the ends for Home and End', () => {
        expect(slideForKey('Home', 2, 3)).toBe(0);
        expect(slideForKey('End', 0, 3)).toBe(2);
    });

    it('answers null for a key the carousel does not own, so Tab keeps tabbing', () => {
        expect(slideForKey('Tab', 1, 3)).toBeNull();
        expect(slideForKey('a', 1, 3)).toBeNull();
        expect(slideForKey('ArrowUp', 1, 3)).toBeNull();
    });

    it('answers null when there is nothing to move through', () => {
        expect(slideForKey('ArrowRight', 0, 0)).toBeNull();
    });
});

describe('slideStatus', () => {
    it('counts from one, for a person', () => {
        expect(slideStatus(0, 4)).toBe('Picture 1 of 4');
        expect(slideStatus(3, 4)).toBe('Picture 4 of 4');
    });

    it('says so when there are no pictures', () => {
        expect(slideStatus(0, 0)).toBe('No pictures');
    });
});
