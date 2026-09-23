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
 * The English sentences are catalog keys (ADR-0068, #132); the German sits
 * in the root catalog. No catalog is loaded under vitest, so `trans()` answers
 * the English with its placeholders filled — which is what the tests pin.
 * Functions, never module-level constants: `trans()` at import time would run
 * before the catalog is installed.
 */

import { trans } from 'laravel-vue-i18n';

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
        ? trans('You are creating an account on :brand, a site of :operator.', {
              brand: brandName,
              operator: operatorName,
          })
        : trans('Sign in on :brand with your :operator account.', {
              brand: brandName,
              operator: operatorName,
          });
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
        ? trans('The same account works on every site of :operator:', {
              operator: operatorName,
          })
        : trans('It also applies on:');
}

/**
 * The surfaces on which an account comes into existence, and the two ways
 * into an account that already exists. `auth/Register` writes a password;
 * `auth/CompleteSocialSignup` writes none at all — that is the point of the
 * nullable column (`Konventix\Identity\Actions\CompleteSocialSignup`).
 */
type SignUpSurface = 'auth/Register' | 'auth/CompleteSocialSignup';

/**
 * ADR-0054 point 6, on the page rather than only in the mail (#241).
 *
 * Deliberately unconditional: it is shown to everyone who reaches a sign-up
 * form, whether or not the address they are about to type already has an
 * account. A notice that appeared only for an existing address — or only
 * after a submit that found one — would answer "does this person have an
 * account here" for any passer-by, which is exactly the enumeration oracle
 * ADR-0016 forbids. This function takes the page, the operator's name and how
 * many brands the operator has: no address, no account state. Saying it to
 * everyone costs nothing and tells the one person who needs to hear it why
 * their account from the operator's other site already works.
 *
 * Two wordings, because the two surfaces offer different ways back in
 * (#241 review finding 7):
 *
 * - on `auth/Register` the account being described has a password, so the
 *   advice is to sign in with it;
 * - on `auth/CompleteSocialSignup` the *new* account would have none, and an
 *   address that already belongs to somebody is refused rather than linked
 *   (`SocialAuthController::store` — linking on a match would be account
 *   takeover by typing). Telling that visitor "the same password works here"
 *   points them at a form they cannot complete, so the advice is to sign in
 *   with the account they already have and connect this provider afterwards
 *   from their security settings, which is the path that controller names.
 *
 * Null on every other surface, and null for a single-brand operator: there is
 * no other site the account could have come from, and the sentence would only
 * puzzle.
 */
export function sharedAccountNoticeFor(
    pageComponent: string,
    operatorName: string,
    brandCount: number,
): string | null {
    const surfaces: SignUpSurface[] = [
        'auth/Register',
        'auth/CompleteSocialSignup',
    ];

    if (brandCount < 2 || !surfaces.includes(pageComponent as SignUpSurface)) {
        return null;
    }

    if (pageComponent === 'auth/CompleteSocialSignup') {
        return trans(
            'Already have an account from another :operator site? Sign in with that account rather than creating a second one — it already works here — and connect this sign-in method afterwards from your security settings.',
            { operator: operatorName },
        );
    }

    return trans(
        'Already have an account from another :operator site? Sign in instead — the same email address and password work here, and you keep everything you already have there.',
        { operator: operatorName },
    );
}

/**
 * Art. 13 DSGVO: the controller is named at the moment the data is
 * collected. The trailing clause is the privacy-policy link, which the
 * component renders and which is absent where the brand publishes no
 * policy — the duty to name the controller does not depend on a page
 * existing, and a dead link from that very sentence is worse than none.
 */
export function controllerSentence(legalName: string): string {
    return trans(':controller is responsible for processing your data', {
        controller: legalName,
    });
}
