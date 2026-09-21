<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Container\Container;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Laravel\Socialite\Contracts\Factory;
use SocialiteProviders\Steam\Provider as SteamDriver;

/**
 * The Steam handshake itself.
 *
 * Steam is OpenID 2.0: the callback arrives as a plain GET carrying an
 * assertion, and the ONLY thing standing between that and a total
 * authentication bypass is the `check_authentication` round-trip back to
 * Steam. These tests drive that round-trip through a mocked transport so both
 * answers can be exercised — the forged-callback case below is the one that
 * matters.
 */

/**
 * Point Socialite's Steam driver at a canned Steam. Returns the queue so a test
 * can assert what was actually asked.
 *
 * Helper name prefixed: Pest helpers are process-global across every module's
 * test files (.ai/rules/tests.md).
 */
function identitySteamRespondsWith(string $body): void
{
    $mock = new MockHandler([new Response(200, [], $body)]);

    // Socialite's factory is a container singleton that caches its drivers, so
    // the instance configured here is the very one SteamProvider will use.
    Socialite::driver('steam')->setHttpClient(
        new Client(['handler' => HandlerStack::create($mock)])
    );
}

/**
 * A callback shaped the way Steam sends one. The signature values are
 * arbitrary: whether they are genuine is exactly what the round-trip decides,
 * not something this application can tell by looking.
 */
function identitySteamCallbackParams(string $steamId = '76561198000000001'): array
{
    return [
        'openid_ns' => 'http://specs.openid.net/auth/2.0',
        'openid_mode' => 'id_res',
        'openid_op_endpoint' => 'https://steamcommunity.com/openid/login',
        'openid_claimed_id' => 'https://steamcommunity.com/openid/id/'.$steamId,
        'openid_identity' => 'https://steamcommunity.com/openid/id/'.$steamId,
        'openid_return_to' => 'http://localhost/auth/steam/callback',
        'openid_response_nonce' => '2026-08-19T10:00:00Zabcdef',
        'openid_assoc_handle' => '1234567890',
        'openid_signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid_sig' => 'Zm9yZ2VkLXNpZ25hdHVyZQ==',
    ];
}

it('accepts a callback Steam confirms, and returns the SteamID64', function (): void {
    $this->withBrand();
    identitySteamRespondsWith("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n");

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000001',
    ]);

    $this->withSession(['identity.social.steam.nonce' => 'a-known-nonce'])
        ->get('http://localhost/auth/steam/callback?'.http_build_query(
            ['nonce' => 'a-known-nonce'] + identitySteamCallbackParams()
        ))
        ->assertRedirect();

    expect(auth()->id())->toBe($user->getKey());
});

/**
 * The test this whole slice exists to pass.
 *
 * Everything below is a well-formed callback naming a real-looking SteamID64,
 * with a nonce that matches the session — i.e. the best a forger can do
 * without Steam's cooperation. Steam says `is_valid:false`, and that must be
 * the end of it. If this test ever passes for the wrong reason, anyone can log
 * in as anyone.
 */
it('refuses a forged callback Steam does not confirm', function (): void {
    $this->withBrand();
    identitySteamRespondsWith("ns:http://specs.openid.net/auth/2.0\nis_valid:false\n");

    $user = User::factory()->create();
    SocialIdentity::factory()->for($user)->create([
        'provider' => 'steam',
        'provider_user_id' => '76561198000000001',
    ]);

    $this->withSession(['identity.social.steam.nonce' => 'a-known-nonce'])
        ->get('http://localhost/auth/steam/callback?'.http_build_query(
            ['nonce' => 'a-known-nonce'] + identitySteamCallbackParams()
        ))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

/**
 * The nonce is ours, not Steam's: OpenID 2.0 has no `state` parameter, so
 * without this an attacker holding their own valid Steam assertion could get a
 * signed-in victim to open the callback and attach the attacker's Steam
 * account to the victim's login.
 */
it('refuses a callback that carries no nonce of ours', function (): void {
    $this->withBrand();
    identitySteamRespondsWith("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n");

    $this->get('http://localhost/auth/steam/callback?'.http_build_query(
        identitySteamCallbackParams()
    ))->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('refuses a callback whose nonce is not the one we issued', function (): void {
    $this->withBrand();
    identitySteamRespondsWith("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n");

    $this->withSession(['identity.social.steam.nonce' => 'the-real-nonce'])
        ->get('http://localhost/auth/steam/callback?'.http_build_query(
            ['nonce' => 'a-guessed-nonce'] + identitySteamCallbackParams()
        ))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('sends people to Steam with a callback on the brand they came from', function (): void {
    $this->withBrand();

    $response = $this->get('http://localhost/auth/steam/redirect');

    $response->assertRedirectContains('steamcommunity.com/openid/login');
    $response->assertRedirectContains(urlencode('http://localhost/auth/steam/callback'));

    // The nonce was issued and remembered, or the callback could never match.
    expect(session('identity.social.steam.nonce'))->toBeString()->not->toBeEmpty();
});

it('still knows the Steam driver on a factory resolved after Octane flushed it', function (): void {
    // config/octane.php flushes Socialite's factory before every request, so
    // the instance the boot-time `SocialiteWasCalled` extended is gone by the
    // time the first login arrives. Every login on the dev cluster answered
    // "Driver [steam] not supported" while this suite was green (2026-09-18).
    app(Factory::class)->driver('steam');
    app()->forgetInstance(Factory::class);

    expect(app(Factory::class)->driver('steam'))->toBeInstanceOf(SteamDriver::class);
});

it('still knows the Steam driver on a factory the Octane sandbox resolved', function (): void {
    // Octane clones the worker's base application into a sandbox for every
    // request (#142), flushes the factory, and the *sandbox* resolves it
    // anew. The afterResolving callback that re-teaches the driver used to
    // extend the factory of `$this->app` — the base application — so the
    // sandbox's instance answered "Driver [steam] not supported" on every
    // login of the staging cluster, on the release that carried the fix
    // above (v0.6.4, 2026-09-21). Here the clone is the sandbox, and it is
    // the only container that must have learned the driver.
    app(Factory::class)->driver('steam');

    // What Octane's Worker does per request (CurrentApplication::set, v2.19):
    // the clone is bound as its own `app` and Container, so anything the
    // sandbox builds that asks for the application gets the sandbox. Without
    // those two lines the clone would still hand out the original, and the
    // test would fail for a reason the worker never has.
    $sandbox = clone app();
    $sandbox->instance('app', $sandbox);
    $sandbox->instance(Container::class, $sandbox);
    $sandbox->forgetInstance(Factory::class);

    expect($sandbox->make(Factory::class)->driver('steam'))->toBeInstanceOf(SteamDriver::class);
});

it('404s a provider nobody registered', function (): void {
    $this->withBrand();

    $this->get('http://localhost/auth/discord/redirect')->assertNotFound();
});
