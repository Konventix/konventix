/**
 * The purchase path's legally required wording (#3 PAngV, #75 the
 * §312j BGB "Button-Loesung" and the pre-contractual duties around it),
 * in one place because two pages have to say the same thing and because a
 * formal mistake here is what gets an operator an Abmahnung.
 *
 * The purchase path is hard English today (#132 owns making it
 * translatable); these are the English equivalents of the German phrases
 * the law names, not translations chosen for tone. In particular
 * `ORDER_WITH_OBLIGATION_TO_PAY` is "zahlungspflichtig bestellen" —
 * §312j (3) BGB demands the button say the order obliges payment and
 * nothing vaguer, so "Pay" or "Buy now" is not enough.
 *
 * Plain functions rather than a component: they are the only part of the
 * purchase path a unit test can pin (there is no Vue component runner
 * here, see `vitest.config.ts`), and the exact strings are the thing worth
 * pinning.
 */

/** §312j (3) BGB: the label of the button that concludes the contract. */
export const ORDER_WITH_OBLIGATION_TO_PAY = 'Order with obligation to pay';

/**
 * PAngV: a consumer price is the end price, and it has to say so. The
 * `net` case is the B2B display a brand may choose for its own audience,
 * where the net amount is the honest one and the label says which it is.
 */
export function taxNote(priceDisplay: 'gross' | 'net'): string {
    return priceDisplay === 'gross' ? 'incl. VAT' : 'excl. VAT';
}

/**
 * How and when what was bought arrives (#75). `settlesInstantly` is true
 * where the brand offers at least one hosted checkout account, false where
 * it settles by bank transfer only — the two cases differ in *when*, which
 * is exactly half of what has to be stated.
 */
export function fulfilmentNote(settlesInstantly: boolean): string {
    return settlesInstantly
        ? 'Delivery is by email: your order confirmation, and your ticket where the order contains one, are sent to your email address as soon as the payment is confirmed — usually within a few minutes.'
        : 'Delivery is by email: this organizer settles by bank transfer, so the payment details arrive with your order confirmation, and your ticket follows once the transfer has been received.';
}

/**
 * Which of the two amounts a brand's `price_display` says to print. Both
 * arrive from the server (`ShopProductData`); this only chooses, so the
 * browser never does arithmetic on a price (#3).
 */
export function displayUnitAmount(priced: {
    unitAmountNet: string;
    unitAmountGross: string;
    priceDisplay: 'gross' | 'net';
}): string {
    return priced.priceDisplay === 'gross'
        ? priced.unitAmountGross
        : priced.unitAmountNet;
}
