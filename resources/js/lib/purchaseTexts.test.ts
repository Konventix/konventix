import { describe, expect, it } from 'vitest';
import {
    displayUnitAmount,
    fulfilmentNote,
    noVatSmallBusiness,
    orderWithObligationToPay,
    taxNote,
} from './purchaseTexts';

/**
 * #75: the exact wording is the requirement, so it is pinned. A change to
 * any of these strings is a legal decision, and this test is where it has
 * to be made deliberately. No catalog is loaded here, so `trans()` answers
 * the English key — the key is what is pinned; its German value lives in
 * `app-modules/commerce/lang/de.json`.
 */
describe('purchase path wording', () => {
    it('labels the order button with the obligation to pay (§312j (3) BGB)', () => {
        expect(orderWithObligationToPay()).toBe('Order with obligation to pay');
    });

    it('names VAT inclusion for a gross display and exclusion for a net one', () => {
        expect(taxNote('gross')).toBe('incl. VAT');
        expect(taxNote('net')).toBe('excl. VAT');
    });

    it('says how and when what was bought arrives, in both settlement cases', () => {
        expect(fulfilmentNote(true)).toContain('by email');
        expect(fulfilmentNote(true)).toContain(
            'as soon as the payment is confirmed',
        );

        expect(fulfilmentNote(false)).toContain('by email');
        expect(fulfilmentNote(false)).toContain('bank transfer');
        expect(fulfilmentNote(false)).toContain(
            'once the transfer has been received',
        );
    });

    it('prints the end price where the brand displays gross, the net one otherwise', () => {
        const priced = { unitAmountNet: '19.00', unitAmountGross: '22.61' };

        expect(displayUnitAmount({ ...priced, priceDisplay: 'gross' })).toBe(
            '22.61',
        );
        expect(displayUnitAmount({ ...priced, priceDisplay: 'net' })).toBe(
            '19.00',
        );
    });
});

describe('taxNote under the small-business scheme', () => {
    it('states the absence rather than claiming a tax that is not there', () => {
        // ADR-0030, GitLab #244: the §19 election zeroes VAT on every line,
        // so gross equals net and both ordinary labels would be false.
        expect(taxNote('gross', true)).toBe('no VAT charged (§ 19 UStG)');
        expect(taxNote('net', true)).toBe('no VAT charged (§ 19 UStG)');
    });

    it('keeps the ordinary labels for every brand that does charge VAT', () => {
        expect(taxNote('gross', false)).toBe('incl. VAT');
        expect(taxNote('net', false)).toBe('excl. VAT');
    });

    it('never prints the §19 note by accident', () => {
        // The default is "not a Kleinunternehmer": the note is a positive
        // legal claim, so a surface that has not been told stays on the
        // ordinary wording rather than asserting an election it knows
        // nothing about.
        expect(taxNote('gross')).toBe('incl. VAT');
        expect(taxNote('net')).toBe('excl. VAT');
        expect(noVatSmallBusiness()).toBe('no VAT charged (§ 19 UStG)');
    });
});
