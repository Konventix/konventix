import { describe, expect, it } from 'vitest';
import { registrationListSummary } from './listSummary';

describe('registrationListSummary', () => {
    it('says something different in each toggle state, which is what a live region reports', () => {
        // The page stays mounted across the toggle (`preserve-state` on the
        // link, NEW-3), so this text changing in place is the announcement.
        const hidden = registrationListSummary(1, 2, false);
        const all = registrationListSummary(3, 2, true);

        expect(hidden).toBe('Showing 1 registration, 2 cancelled hidden.');
        expect(all).toBe(
            'Showing all 3 registrations, cancelled ones included.',
        );
        expect(hidden).not.toBe(all);
    });

    it('agrees with itself about singular and plural', () => {
        expect(registrationListSummary(0, 1, false)).toBe(
            'Showing 0 registrations, 1 cancelled hidden.',
        );
        expect(registrationListSummary(1, 0, true)).toBe(
            'Showing all 1 registration, cancelled ones included.',
        );
    });
});
