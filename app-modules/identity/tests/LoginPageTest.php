<?php

declare(strict_types=1);

/**
 * GitLab #81: the backend Steam route has worked since slice 15, but nothing
 * on the login page pointed at it — Steam sign-in was reachable only from
 * `LinkedAccounts.vue`, which links an already-signed-in account rather than
 * authenticating one. Most migrated accounts sign in by Steam, so this is
 * the one surface that matters on cutover day.
 *
 * Asserted against the SSR-rendered page (`.ai/rules/tests.md` /
 * `SettingsLayoutTest`'s own precedent), because the button is markup, not
 * a prop this or any other Inertia assertion would see.
 */
it('offers Steam sign-in on the login page, pointed at the real redirect route', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee(route('social.redirect', ['provider' => 'steam'], absolute: false), escape: false);
});

/**
 * TODO.md: "must pass the login form's own remember checkbox through the
 * redirect" — unticked is the page's default state, so the link's initial
 * render must default the query flag to unset/false rather than always
 * remembering.
 */
it('does not default the Steam link to a remembered sign-in', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('remember=1', escape: false);
});
