import { describe, expect, it } from 'vitest';
import { formatScore } from './scoreValue';

describe('formatScore', () => {
    it('prints a dash and no unit for an entrant without a verified score', () => {
        expect(formatScore(null, 's', 2)).toBe('—');
    });

    it('prints the stage’s decimals with a decimal comma', () => {
        // 61.5 at two places is 61,50; at none it rounds half up to 62.
        expect(formatScore(61.5, 's', 2)).toBe('61,50 s');
        expect(formatScore(61.5, 'Punkte', 0)).toBe('62 Punkte');
    });

    it('groups thousands the German way', () => {
        // de-DE groups with a point: 1234.5 at one place is 1.234,5.
        expect(formatScore(1234.5, 'Punkte', 1)).toBe('1.234,5 Punkte');
    });

    it('prints up to three places without a setting', () => {
        expect(formatScore(41.9, 's')).toBe('41,9 s');
    });
});
