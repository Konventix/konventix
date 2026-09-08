/**
 * The sentences the auth surfaces say about whose account this is
 * (#184, #241, ADR-0054 points 5 and 6).
 *
 * Plain functions rather than logic inside the component: they are the only
 * part of the notice a unit test can pin (there is no Vue component runner
 * here, see `vitest.config.ts`), and the exact wording is the thing worth
 * pinning — one of these sentences discharges an Art. 13 DSGVO duty and
 * another one must not become an account-enumeration oracle.
 *
 * The auth surfaces are hard English today, like the purchase path (#132
 * owns making them translatable).
 */

/** One of the operator's brands, as the presence prop carries it. */
export type PresenceBrand = {
    id: string;
    name: string;
    url: string | null;
    current: boolean;
};

/**
 * Whose account is being created or used, and where.
 *
 * #184 named the operator and left the visitor to work out which of the
 * listed sites they were standing on; #241 names the brand too, because a
 * person arrives knowing the brand and not the house behind it. The brand
 * comes first for that reason.
 */
export function accountIntro(
    operatorName: string,
    brandName: string,
    creatingAccount: boolean,
): string {
    return creatingAccount
        ? `You are creating an account on ${brandName}, a site of ${operatorName}.`
        : `Sign in on ${brandName} with your ${operatorName} account.`;
}

/**
 * The lead-in to the brand list, or null for an operator with a single
 * brand — an "it also applies on:" with nothing after the colon is worse
 * than no sentence.
 */
export function brandListIntro(
    operatorName: string,
    creatingAccount: boolean,
    brandCount: number,
): string | null {
    if (brandCount < 2) {
        return null;
    }

    return creatingAccount
        ? `The same account works on every site of ${operatorName}:`
        : 'It also applies on:';
}

/**
 * ADR-0054 point 6, on the page rather than only in the mail (#241).
 *
 * Deliberately unconditional: it is shown to everyone who reaches the
 * sign-up form, whether or not the address they are about to type already
 * has an account. A notice that appeared only for an existing address —
 * or only after a submit that found one — would answer "does this person
 * have an account here" for any passer-by, which is exactly the
 * enumeration oracle ADR-0016 forbids. Saying it to everyone costs nothing
 * and tells the one person who needs to hear it why their password from
 * the operator's other site already works.
 *
 * Null for a single-brand operator: there is no other site the account
 * could have come from, and the sentence would only puzzle.
 */
export function sharedAccountNotice(
    operatorName: string,
    brandCount: number,
): string | null {
    if (brandCount < 2) {
        return null;
    }

    return `Already have an account from another ${operatorName} site? Sign in instead — the same email address and password work here, and you keep everything you already have there.`;
}

/**
 * Art. 13 DSGVO: the controller is named at the moment the data is
 * collected. The trailing clause is the privacy-policy link, which the
 * component renders and which is absent where the brand publishes no
 * policy — the duty to name the controller does not depend on a page
 * existing, and a dead link from that very sentence is worse than none.
 */
export function controllerSentence(legalName: string): string {
    return `${legalName} is responsible for processing your data`;
}
