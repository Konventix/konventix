/**
 * The purchase path's legally required wording (#3 PAngV, #75 the
 * §312j BGB "Button-Loesung" and the pre-contractual duties around it),
 * in one place because two pages have to say the same thing and because a
 * formal mistake here is what gets an operator an Abmahnung.
 *
 * The English strings are the catalog keys (ADR-0068); the German the law
 * names sits in `app-modules/commerce/lang/de.json`, which is the wording
 * that binds. The English is its equivalent, not a translation chosen for
 * tone. In particular `orderWithObligationToPay()` is "zahlungspflichtig
 * bestellen" — §312j (3) BGB demands the button say the order obliges
 * payment and nothing vaguer, so "Pay" or "Buy now" is not enough.
 *
 * Functions, never module-level constants: a `trans()` evaluated at import
 * time runs before `installI18n()` has the catalog and would freeze the
 * English.
 *
 * Plain functions rather than a component: they are the only part of the
 * purchase path a unit test can pin (there is no Vue component runner
 * here, see `vitest.config.ts`), and the exact strings are the thing worth
 * pinning.
 *
 * App-level rather than Commerce's own since #240: `/shop` lists
 * Registration's types beside Commerce's products and both must print the
 * same end price with the same label, and a module reaching into another
 * module's `resources/js` by relative path is the one coupling
 * `.ai/rules/resources.md` names outright. This is shared purchase-path UI,
 * so it lives where shared UI lives — beside `datetime.ts`, which crossed
 * the same line for the same reason.
 */

import { trans } from 'laravel-vue-i18n';

/** §312j (3) BGB: the label of the button that concludes the contract. */
export function orderWithObligationToPay(): string {
    return trans('Order with obligation to pay');
}

/** §19 (1) sentence 4 UStG: the absence of VAT, stated. */
export function noVatSmallBusiness(): string {
    return trans('no VAT charged (§ 19 UStG)');
}

/**
 * PAngV: a consumer price is the end price, and it has to say so. The
 * `net` case is the B2B display a brand may choose for its own audience,
 * where the net amount is the honest one and the label says which it is.
 *
 * `smallBusinessScheme` is the operator's §19 UStG election as
 * `GermanVat::treatmentFor()` resolved it — `TaxTreatment::SmallBusiness`,
 * carried to the page as that one fact (GitLab #244). Under it the
 * calculator puts no VAT on any line at all, gross equals net, and both
 * "incl. VAT" and "excl. VAT" claim a tax that is not being charged, while
 * §19 (1) sentence 4 UStG in fact requires the absence to be stated.
 *
 * Deliberately *not* keyed on a zero rate: a rate of "0.00" is an amount,
 * the §19 election is a status, and the two are not the same fact — a
 * zero-rated line under an ordinary operator, or the reverse charge a
 * billing party with a foreign VAT ID triggers, both produce no VAT on the
 * line without making this brand a Kleinunternehmer. Only the resolved
 * treatment says which sentence is true.
 *
 * Defaults to false so a surface that has not been told keeps the ordinary
 * wording: the §19 note is a positive claim and is never printed by
 * accident.
 */
export function taxNote(
    priceDisplay: 'gross' | 'net',
    smallBusinessScheme = false,
): string {
    if (smallBusinessScheme) {
        return noVatSmallBusiness();
    }

    return priceDisplay === 'gross' ? trans('incl. VAT') : trans('excl. VAT');
}

/**
 * How and when what was bought arrives (#75). `settlesInstantly` is true
 * where the brand offers at least one hosted checkout account, false where
 * it settles by bank transfer only — the two cases differ in *when*, which
 * is exactly half of what has to be stated.
 */
export function fulfilmentNote(settlesInstantly: boolean): string {
    return settlesInstantly
        ? trans(
              'Delivery is by email: your order confirmation, and your ticket where the order contains one, are sent to your email address as soon as the payment is confirmed — usually within a few minutes.',
          )
        : trans(
              'Delivery is by email: this organizer settles by bank transfer, so the payment details arrive with your order confirmation, and your ticket follows once the transfer has been received.',
          );
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
