import { describe, expect, it } from 'vitest';
import {
    counterText,
    localErrors,
    MESSAGE_MAX,
    MESSAGE_MIN,
    successMessage,
    supportCategories,
    toggleCategory,
    transportErrorMessage,
} from './supportForm';

describe('the category chips', () => {
    /**
     * The five of #88 in the order the dialog offers them, and the same
     * values `Konventix\Support\Enums\SupportCategory` stores and labels
     * its metric with — a rename on either side has to be a rename on both.
     */
    it('offers the five categories in the order the design fixes', () => {
        expect(supportCategories().map((option) => option.value)).toEqual([
            'purchase_affected',
            'visibility',
            'function',
            'display',
            'suggestion',
        ]);
    });

    it('is a multi-select: a second click turns a chip off again', () => {
        let selected: string[] = [];

        selected = toggleCategory(selected, 'purchase_affected');
        selected = toggleCategory(selected, 'display');

        expect(selected).toEqual(['purchase_affected', 'display']);

        selected = toggleCategory(selected, 'purchase_affected');

        expect(selected).toEqual(['display']);
    });
});

describe('what the dialog can say before asking the server', () => {
    it('refuses an empty category list', () => {
        const errors = localErrors(
            {
                categories: [],
                message: 'x'.repeat(MESSAGE_MIN),
                email: 'a@b.test',
                contactConsent: false,
            },
            true,
        );

        expect(errors.categories).toBeDefined();
        expect(errors.message).toBeUndefined();
    });

    it('holds the 10 to 4000 character bound the endpoint holds', () => {
        const base = {
            categories: ['function'],
            email: 'a@b.test',
            contactConsent: false,
        };

        expect(
            localErrors({ ...base, message: 'broken' }, true).message,
        ).toBeDefined();
        expect(
            localErrors({ ...base, message: 'x'.repeat(MESSAGE_MAX + 1) }, true)
                .message,
        ).toBeDefined();
        expect(
            localErrors({ ...base, message: 'x'.repeat(MESSAGE_MIN) }, true)
                .message,
        ).toBeUndefined();
    });

    /**
     * Consent is deliberately *not* required: a report filed by somebody
     * who does not want to be contacted is still a report worth having
     * (D-SUP-07). The address is required only for a guest — an account
     * already has one.
     */
    it('requires an address from a guest and from nobody else, and never requires consent', () => {
        const values = {
            categories: ['function'],
            message: 'x'.repeat(MESSAGE_MIN),
            email: '   ',
            contactConsent: false,
        };

        expect(localErrors(values, true).email).toBeDefined();
        expect(localErrors(values, false).email).toBeUndefined();
        expect(localErrors(values, false)).toEqual({});
    });
});

describe('the copy', () => {
    it('counts towards the minimum first and the maximum after', () => {
        expect(counterText(3)).toBe('3 of 10 characters minimum');
        expect(counterText(50)).toBe('50 of 4000 characters');
    });

    it('tells a guest where the confirmation went and an account where to look', () => {
        expect(successMessage('A1B2C3D4', true, 'you@example.test')).toBe(
            'Thanks — your reference is A1B2C3D4. We sent a confirmation to you@example.test.',
        );
        expect(successMessage('A1B2C3D4', false, '')).toContain(
            'My support requests',
        );
    });

    it('gives the rate limit its own sentence', () => {
        expect(transportErrorMessage(429)).toBe(
            'Too many reports, please try again in a few minutes.',
        );
        expect(transportErrorMessage(500)).not.toContain('few minutes');
        expect(transportErrorMessage(null)).toContain('connection');
    });
});
