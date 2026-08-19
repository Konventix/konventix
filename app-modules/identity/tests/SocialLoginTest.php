<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Models\BrandMembership;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * One callback, three outcomes: sign in somebody we recognise, link a new
 * identity for somebody already signed in, or start a signup. Which one it is
 * cannot be known until the provider has answered, which is why they share a
 * route.
 */
function identityCallbackFrom(object $test, string $steamId, ?string $nonce = 'nonce-value')
{
    Socialite::driver('steam')->setHttpClient(new Client([
        'handler' => HandlerStack::create(
            new MockHandler([new Response(200, [], "ns:http://specs.openid.net/auth/2.0\nis_valid:true\n")])
        ),
    ]));

    $query = [
        'openid_ns' => 'http://specs.openid.net/auth/2.0',
        'openid_mode' => 'id_res',
        'openid_op_endpoint' => 'https://steamcommunity.com/openid/login',
        'openid_claimed_id' => 'https://steamcommunity.com/openid/id/'.$steamId,
        'openid_identity' => 'https://steamcommunity.com/openid/id/'.$steamId,
        'openid_return_to' => 'http://localhost/auth/steam/callback',
        'openid_response_nonce' => '2026-08-19T10:00:00Zabcdef',
        'openid_assoc_handle' => '1234567890',
        'openid_signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid_sig' => 'c2lnbmF0dXJl',
        'nonce' => $nonce,
    ];

    return $test->withSession(['identity.social.steam.nonce' => $nonce])
        ->get('http://localhost/auth/steam/callback?'.http_build_query($query));
}

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
