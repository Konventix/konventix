<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Factory as Socialite;
use SocialiteProviders\Steam\Provider as SteamDriver;
use Throwable;

/**
 * Steam, the one social driver at release (decision log D-IDN-01 as amended).
 *
 * Steam is OpenID 2.0, not OAuth2, which makes it cheaper and stranger than a
 * normal provider:
 *
 * - **No app registration, no client id, no client secret.** There is nothing
 *   to provision per brand, so unlike per-brand mail transports (ADR-0028)
 *   this needs no brand-owned credentials at all. A brand's own domain is the
 *   entire configuration.
 * - **No `state` parameter.** The driver is `stateless`, so OAuth's built-in
 *   CSRF defence does not exist here and we supply our own — see the nonce
 *   below. Without it, an attacker holding a valid Steam assertion could get a
 *   signed-in victim to open the callback and attach the *attacker's* Steam
 *   account to the victim's login.
 * - **No email, ever.** `SocialIdentityData::$email` stays null and onboarding
 *   asks instead.
 *
 * The assertion itself is verified by the package: `validate()` POSTs the
 * returned parameters back to Steam with `openid.mode=check_authentication`
 * and requires `is_valid:true`. That round-trip is the whole security of the
 * flow — without it a handcrafted GET naming any SteamID64 would authenticate
 * as that person — so this class treats anything short of a clean `true` as a
 * refusal, and a test forges a callback to prove it.
 */
final class SteamProvider implements SocialProvider
{
    public const KEY = 'steam';

    /**
     * Namespaced by provider: a second driver must not be able to satisfy
     * Steam's nonce with its own.
     */
    private const SESSION_NONCE = 'identity.social.steam.nonce';

    public function __construct(private readonly Socialite $socialite) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Steam';
    }

    public function redirect(): RedirectResponse
    {
        $nonce = Str::random(40);

        request()->session()->put(self::SESSION_NONCE, $nonce);

        return $this->driver($nonce)->redirect();
    }

    public function identityFromCallback(Request $request): SocialIdentityData
    {
        $nonce = $this->consumeNonce($request);

        $driver = $this->driver($nonce);

        try {
            $isValid = $driver->validate();
        } catch (Throwable $exception) {
            // The package throws on a missing parameter or a rejected host as
            // well as returning false; both mean the same thing here.
            throw new SocialAuthenticationFailed('The Steam assertion could not be verified.', previous: $exception);
        }

        if ($isValid !== true) {
            throw new SocialAuthenticationFailed('Steam rejected the assertion.');
        }

        // `parseSteamID()` writes 0 when the claimed identifier does not match
        // the expected form, so a falsy id is a failed parse and not a person.
        $steamId = (string) $driver->steamId;

        if ($steamId === '' || $steamId === '0') {
            throw new SocialAuthenticationFailed('The Steam assertion carried no usable identifier.');
        }

        $profile = $this->profileFor($driver);

        return new SocialIdentityData(
            provider: self::KEY,
            providerUserId: $steamId,
            nickname: $profile['nickname'],
            avatarUrl: $profile['avatarUrl'],
        );
    }

    /**
     * The nonce must be read before `validate()` runs: the driver's
     * `normalizeOpenidKeys()` calls `$request->replace()`, which swaps the
     * whole input bag for the normalized OpenID keys and takes our query
     * parameter with it.
     *
     * Pulled rather than read, so a callback cannot be replayed: one redirect
     * buys exactly one callback.
     *
     * @throws SocialAuthenticationFailed
     */
    private function consumeNonce(Request $request): string
    {
        $presented = (string) $request->query('nonce', '');
        $expected = (string) $request->session()->pull(self::SESSION_NONCE, '');

        if ($expected === '' || $presented === '' || ! hash_equals($expected, $presented)) {
            throw new SocialAuthenticationFailed('The Steam callback carried no matching nonce.');
        }

        return $presented;
    }

    /**
     * Display name and avatar, when this deployment can ask for them.
     *
     * The Steam Web API key is optional by design, and the package is not: its
     * `getUserByToken()` throws outright when the key is empty, which would
     * turn "no key configured" into a 500 on every login. So the profile call
     * only happens when a key exists; without one the identity carries the
     * SteamID64 alone and onboarding collects a display name, which it does
     * anyway.
     *
     * @return array{nickname: ?string, avatarUrl: ?string}
     */
    private function profileFor(SteamDriver $driver): array
    {
        if ((string) config('services.steam.client_secret', '') === '') {
            return ['nickname' => null, 'avatarUrl' => null];
        }

        try {
            $user = $driver->user();
        } catch (Throwable) {
            // Steam's Web API being down must not cost a verified person their
            // login: the identity is already proven, the decoration is not.
            return ['nickname' => null, 'avatarUrl' => null];
        }

        return [
            'nickname' => $user->getNickname() ?: $user->getName(),
            'avatarUrl' => $user->getAvatar(),
        ];
    }

    /**
     * A driver bound to *this* request, with the callback pointing at whatever
     * brand domain the request arrived on.
     *
     * Two things are deliberate. The redirect URL is built per call rather
     * than read from `services.steam.redirect`: a static value would send
     * every brand's users back to one host and break brand-scoped sessions.
     * And the request is re-set every time, because Socialite's factory is a
     * container singleton that caches its drivers — under Octane a cached
     * driver would otherwise keep validating against the request it was first
     * built for.
     */
    private function driver(string $nonce): SteamDriver
    {
        /** @var SteamDriver $driver */
        $driver = $this->socialite->driver(self::KEY);

        $driver->setRequest(request());

        $driver->redirectUrl(route('social.callback', [
            'provider' => self::KEY,
            'nonce' => $nonce,
        ]));

        return $driver;
    }
}
