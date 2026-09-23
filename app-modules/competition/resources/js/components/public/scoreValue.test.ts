import { describe, expect, it } from 'vitest';
import { formatScore } from './scoreValue';

describe('formatScore', () => {
    it('prints a dash and no unit for an entrant without a verified score', () => {
        expect(formatScore(null, 's')).toBe('—');
    });

    it('prints the value with its unit', () => {
        expect(formatScore(41.9, 's')).toBe('41.9 s');
    });
});
