import { describe, expect, it } from 'vitest';
import {
    accountIntro,
    brandListIntro,
    controllerSentence,
    sharedAccountNoticeFor,
} from './operatorPresenceText';

/**
 * #241: the auth surfaces have to name the brand a visitor is standing on
 * *and* the operator behind it, and explain on the page — not only in a
 * mail — why an account from a sibling brand already works here.
 */
describe('accountIntro', () => {
    it('names the brand and the operator when an account is being created', () => {
        expect(accountIntro('Acme Events', 'Alpha Con', true)).toBe(
            'You are creating an account on Alpha Con, a site of Acme Events.',
        );
    });

    it('names both in the past tense when signing in', () => {
        expect(accountIntro('Acme Events', 'Alpha Con', false)).toBe(
            'Sign in on Alpha Con with your Acme Events account.',
        );
    });

    it('never drops the brand, which is the name the visitor arrived knowing', () => {
        expect(accountIntro('Acme Events', 'Alpha Con', true)).toContain(
            'Alpha Con',
        );
        expect(accountIntro('Acme Events', 'Alpha Con', false)).toContain(
            'Alpha Con',
        );
    });
});

describe('brandListIntro', () => {
    it('introduces the list of sibling sites on both surfaces', () => {
        expect(brandListIntro('Acme Events', true, 3)).toBe(
            'The same account works on every site of Acme Events:',
        );
        expect(brandListIntro('Acme Events', false, 3)).toBe(
            'It also applies on:',
        );
    });

    it('says nothing for a single-brand operator', () => {
        // An "it also applies on:" with nothing after the colon.
        expect(brandListIntro('Acme Events', true, 1)).toBeNull();
        expect(brandListIntro('Acme Events', false, 1)).toBeNull();
    });
});

describe('sharedAccountNoticeFor', () => {
    it('explains the shared account on the registration page itself', () => {
        const notice = sharedAccountNoticeFor(
            'auth/Register',
            'Acme Events',
            2,
        );

        expect(notice).toContain(
            'Already have an account from another Acme Events site?',
        );
        expect(notice).toContain(
            'the same email address and password work here',
        );
    });

    it('never offers a password on the social-signup page, where the account has none', () => {
        // `CompleteSocialSignup` creates an account with `password => null` —
        // that is the point of the nullable column. Telling that visitor their
        // password works here sends them to a form they cannot complete
        // (#241 review finding 7). And the address they type is refused if it
        // is already taken, deliberately unlinked (`SocialAuthController`), so
        // the advice is to sign in with the account they already have and
        // connect this provider afterwards.
        const notice = sharedAccountNoticeFor(
            'auth/CompleteSocialSignup',
            'Acme Events',
            2,
        );

        expect(notice).not.toContain('password');
        expect(notice).toContain(
            'Already have an account from another Acme Events site?',
        );
        expect(notice).toContain('connect this sign-in method');
    });

    it('says nothing on a surface where no account is being created', () => {
        expect(
            sharedAccountNoticeFor('auth/Login', 'Acme Events', 2),
        ).toBeNull();
        expect(
            sharedAccountNoticeFor('auth/ForgotPassword', 'Acme Events', 2),
        ).toBeNull();
    });

    it('depends on nothing but the page and the operator, so it is no enumeration oracle', () => {
        // ADR-0016: the sign-up surface must never answer "does this address
        // have an account". The notice takes the page component, the operator
        // name and how many brands the operator has — no address and no
        // account state at all — so the same text is shown to everyone who
        // reaches the form.
        expect(sharedAccountNoticeFor('auth/Register', 'Acme Events', 2)).toBe(
            sharedAccountNoticeFor('auth/Register', 'Acme Events', 2),
        );
        expect(sharedAccountNoticeFor.length).toBe(3);
    });

    it('says nothing for a single-brand operator, which has no other site', () => {
        expect(
            sharedAccountNoticeFor('auth/Register', 'Acme Events', 1),
        ).toBeNull();
        expect(
            sharedAccountNoticeFor(
                'auth/CompleteSocialSignup',
                'Acme Events',
                1,
            ),
        ).toBeNull();
    });
});

describe('controllerSentence', () => {
    it('names the legal entity, which is the Art. 13 duty', () => {
        expect(controllerSentence('Acme Events GmbH')).toBe(
            'Acme Events GmbH is responsible for processing your data',
        );
    });
});
