<?php

declare(strict_types=1);

use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Models\Consent;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Models\User;

/**
 * GitLab #205, operator decision of 08.09.2026: a contract text is asked
 * for at login and at checkout, so the first thing a successful login does
 * is check whether this account still owes agreement to one.
 *
 * The gate lives in Fortify's `LoginResponse`/`TwoFactorLoginResponse`
 * bindings rather than on a route, because there is no route to gate — the
 * moment worth interrupting is the redirect out of the login POST, and it
 * has to keep whatever destination the person was originally headed for.
 */
function loginConsentContractText(): PolicyVersion
{
    return PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create([
        'version' => 'terms-v1',
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);
}

it('sends a login with an outstanding contract text to the consent screen', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    loginConsentContractText();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('policies.required', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('keeps the destination the person was headed for across the consent screen', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    loginConsentContractText();

    // What the `auth` middleware leaves behind when it turns a guest away
    // from a page they asked for.
    $this->withSession(['url.intended' => 'http://localhost/settings/profile'])
        ->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('policies.required', absolute: false))
        ->assertSessionHas('url.intended', 'http://localhost/settings/profile');
});

it('falls back to the post-login home as the destination when there was none', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    loginConsentContractText();

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHas('url.intended', route('dashboard', absolute: false));
});

it('lets a login whose contract texts are all agreed proceed as before', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    $version = loginConsentContractText();
    Consent::factory()->for($version, 'policyVersion')->create(['subject_id' => $user->getKey()]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});

it('does not interrupt a login over a consent-based text — that is the route gate\'s job', function (): void {
    $this->withBrand();
    $user = User::factory()->create();

    PolicyVersion::factory()->for(Policy::factory()->photoConsent())->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Consent,
    ]);

    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));
});
