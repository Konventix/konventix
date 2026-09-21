import { describe, expect, it } from 'vitest';
import { consentListSignature } from './confirmation';
import type { ConfirmationSlot } from './confirmation';

function slot(overrides: Record<string, unknown> = {}): ConfirmationSlot {
    return {
        module: 'compliance',
        component: 'compliance/CheckoutConsent',
        props: {
            required: true,
            policies: [{ slug: 'terms' }, { slug: 'withdrawal' }],
            notices: [],
            ...overrides,
        },
    };
}

describe('consentListSignature', () => {
    it('is stable while the same texts are asked for', () => {
        expect(consentListSignature([slot()])).toBe(
            consentListSignature([slot()]),
        );
    });

    it('changes when a text joins the list', () => {
        const extended = slot({
            policies: [
                { slug: 'terms' },
                { slug: 'withdrawal' },
                { slug: 'stripe-addendum' },
            ],
        });

        expect(consentListSignature([extended])).not.toBe(
            consentListSignature([slot()]),
        );
    });

    it('changes when a text moves from agreed to merely read', () => {
        const informing = slot({
            required: false,
            policies: [],
            notices: [{ slug: 'terms' }, { slug: 'withdrawal' }],
        });

        expect(consentListSignature([informing])).not.toBe(
            consentListSignature([slot()]),
        );
    });

    it('ignores a rewording of the same document', () => {
        const reworded = slot({
            policies: [
                { slug: 'terms', name: 'Terms and conditions (2026)' },
                { slug: 'withdrawal' },
            ],
        });

        expect(consentListSignature([reworded])).toBe(
            consentListSignature([slot()]),
        );
    });
});
