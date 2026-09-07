import { describe, expect, it } from 'vitest';
import { canBookPayment, repaymentDeadline } from './reversals';

/**
 * The rules both order-console views read (ADR-0057 point 8). Tested here
 * rather than through a rendered page for the reason
 * `resources/js/entrance/lib/evaluate.test.ts` gives: the rule is a pure
 * function, and a `v-if` that got it wrong is a bug in the rule.
 */
describe('canBookPayment', () => {
    it('offers the booking dialog for an order that is still owed', () => {
        expect(canBookPayment('pending')).toBe(true);
    });

    it('offers it again once a payment was reversed', () => {
        // `RecordPaymentReversal` deliberately keeps `paid_at`, so a
        // `!paidAt` test hid the button for every reversed order and made
        // the manual repayment path unreachable in the console (I2).
        expect(canBookPayment('payment_reversed')).toBe(true);
    });

    it('offers it for nothing that is settled or called off', () => {
        expect(canBookPayment('paid')).toBe(false);
        expect(canBookPayment('cancelled')).toBe(false);
        expect(canBookPayment('refunded')).toBe(false);
    });
});

describe('repaymentDeadline', () => {
    it('names the newest final reversal’s deadline and ignores an open dispute', () => {
        expect(
            repaymentDeadline([
                { status: 'open', repayDueOn: null },
                { status: 'final', repayDueOn: '2026-09-14' },
            ] as Parameters<typeof repaymentDeadline>[0]),
        ).toBe('2026-09-14');
    });

    it('has no deadline where nothing is owed back yet', () => {
        expect(
            repaymentDeadline([
                { status: 'open', repayDueOn: null },
            ] as Parameters<typeof repaymentDeadline>[0]),
        ).toBeNull();
    });
});
