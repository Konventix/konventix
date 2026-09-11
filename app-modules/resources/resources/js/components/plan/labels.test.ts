import { describe, expect, it } from 'vitest';
import { labelsVisible, placeLabel, selectionAnnouncement } from './labels';

describe('labelsVisible', () => {
    it('hides labels once a 250 mm label would render below 9 px', () => {
        // 0.05 px/mm on screen: 250 mm is 12.5 px at zoom 1, 6.25 px at 0.5.
        expect(labelsVisible(1, 0.05)).toBe(true);
        expect(labelsVisible(0.5, 0.05)).toBe(false);
        expect(labelsVisible(1, 0)).toBe(false);
    });
});

describe('placeLabel', () => {
    it('prefers the label, then the ref, then nothing', () => {
        expect(placeLabel({ label: 'Seat 1', ref: 'A-01' })).toBe('Seat 1');
        expect(placeLabel({ label: null, ref: 'A-01' })).toBe('A-01');
        expect(placeLabel({ label: null, ref: null })).toBe('');
    });
});

describe('selectionAnnouncement', () => {
    it('says what is selected and how many are allowed', () => {
        expect(selectionAnnouncement([], 1)).toBe(
            'No place selected. You can choose 1 place.',
        );
        expect(selectionAnnouncement([], 2)).toBe(
            'No place selected. You can choose 2 places.',
        );
        expect(selectionAnnouncement(['A-01'], 1)).toBe('Selected A-01.');
        expect(selectionAnnouncement(['A-01'], 2)).toBe(
            'Selected A-01 (1 of 2).',
        );
        expect(selectionAnnouncement(['A-01', 'A-02'], 2)).toBe(
            'Selected A-01, A-02 (2 of 2).',
        );
    });
});
