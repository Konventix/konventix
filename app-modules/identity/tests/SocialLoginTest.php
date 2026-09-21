<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Enums\LawfulBasis;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * One callback, three outcomes: sign in somebody we recognise, link a new
 * identity for somebody already signed in, or start a signup. Which one it is
 * cannot be known until the provider has answered, which is why they share a
 * route. The callback helper lives in `tests/Helpers.php` (.ai/rules/tests.md:
 * a helper a second file calls cannot be declared in a `*Test.php`).
 */
it('signs in the account an identity already points at', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000042',
    ]);

    identityCallbackFrom($this, '76561198000000042')->assertRedirect();

    expect(auth()->id())->toBe($user->getKey());
});

/**
 * ADR-0016: every login establishes the brand membership. Social login goes
 * through `Auth::login()`, which fires the same `Login` event the password path
 * does — so this needs no wiring of its own, and this test is what proves that
 * claim rather than assuming it.
 */
it('records the brand membership on a social login, like any other', function (): void {
    $brand = $this->withBrand();

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000043',
    ]);

    identityCallbackFrom($this, '76561198000000043');

    expect(BrandMembership::withoutBrandScope()
        ->where('user_id', $user->getKey())
        ->where('brand_id', $brand->getKey())
        ->exists())->toBeTrue();
});

it('refuses a locked account even with a valid assertion', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['state' => AccountState::Locked]);
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000044',
    ]);

    identityCallbackFrom($this, '76561198000000044')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('refuses an anonymized account even with a valid assertion', function (): void {
    $this->withBrand();

    $user = User::factory()->create(['state' => AccountState::Anonymized]);
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000045',
    ]);

    identityCallbackFrom($this, '76561198000000045')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

/**
 * #81: `MergeUserAccounts` moves every SocialIdentity row off a merged
 * account, so this attaches one directly rather than through the Action —
 * exercising the belt-and-braces branch `logIn()` carries for the same
 * reason its Anonymized check above does, in case that invariant is ever
 * broken.
 */
it('refuses a merged account even with a valid assertion', function (): void {
    $this->withBrand();

    $user = User::factory()->merged()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000149',
    ]);

    identityCallbackFrom($this, '76561198000000149')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('links a new identity for somebody already signed in', function (): void {
    $this->withBrand();
    Event::fake([SocialIdentityLinked::class]);

    $user = User::factory()->create();
    $this->actingAs($user);

    identityCallbackFrom($this, '76561198000000046')
        ->assertRedirect(route('linked-accounts.edit'));

    expect($user->socialIdentities()->where('provider_user_id', '76561198000000046')->exists())->toBeTrue();

    Event::assertDispatched(SocialIdentityLinked::class);
});

/**
 * The identity belongs to one account, full stop. Without this, signing in to
 * account A and presenting account B's Steam login would quietly give A a
 * second key to a login it does not own.
 */
it('refuses to link an identity another account already holds', function (): void {
    $this->withBrand();

    $owner = User::factory()->create();
    SocialIdentity::factory()->for($owner)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000047',
    ]);

    $other = User::factory()->create();
    $this->actingAs($other);

    identityCallbackFrom($this, '76561198000000047')
        ->assertRedirect(route('linked-accounts.edit'))
        ->assertSessionHasErrors('provider');

    // Nothing moved, and nobody was silently switched into somebody else's
    // account: the actor is still themselves.
    expect($other->socialIdentities()->count())->toBe(0)
        ->and($owner->socialIdentities()->count())->toBe(1)
        ->and(auth()->id())->toBe($other->getKey());
});

it('starts a signup when nobody is signed in and nobody is recognised', function (): void {
    $this->withBrand();

    identityCallbackFrom($this, '76561198000000048')
        ->assertRedirect(route('social.complete', ['provider' => 'steam']));

    expect(auth()->check())->toBeFalse()
        ->and(session('identity.social.pending'))->toBeArray();
});

/**
 * WP194 §3.2: a persistent login cookie is not exempt from consent; the
 * "remember me" checkbox is the consent. Steam used to remember everyone.
 */
it('does not set a remember cookie unless the sign-in started with remember me', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam', 'provider_user_id' => '76561198000000050']);

    identityCallbackFrom($this, '76561198000000050')
        ->assertCookieMissing(auth()->guard('web')->getRecallerName());
});

it('sets the remember cookie when the redirect carried remember=1', function (): void {
    $this->withBrand();
    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam', 'provider_user_id' => '76561198000000051']);

    $this->withSession(['identity.social.remember' => true]);

    identityCallbackFrom($this, '76561198000000051')
        ->assertCookie(auth()->guard('web')->getRecallerName());
});

it('records the remember choice when the sign-in starts', function (): void {
    $this->withBrand();

    $this->get('/auth/steam/redirect?remember=1')
        ->assertRedirect()
        ->assertSessionHas('identity.social.remember', true);
});

/**
 * GitLab #205: a social sign-in is a sign-in. The `require-consent` alias on
 * the signup-completion routes cannot cover this — the person is still a
 * guest when that middleware runs — so the gate sits on the redirect the
 * controller hands back, exactly as it sits on Fortify's login response.
 */
it('sends a social sign-in with an outstanding contract text to the consent screen', function (): void {
    $this->withBrand();

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create(['provider' => 'steam', 'provider_user_id' => '76561198000000052']);

    PolicyVersion::factory()->for(Policy::factory()->terms())->published()->create([
        'requires_consent' => true,
        'lawful_basis' => LawfulBasis::Contract,
    ]);

    identityCallbackFrom($this, '76561198000000052')
        ->assertRedirect(route('policies.required', absolute: false));

    expect(auth()->id())->toBe($user->getKey());
});
