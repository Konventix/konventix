<?php

declare(strict_types=1);

use Konventix\Identity\Social\SocialProvider;
use Konventix\Identity\Social\SocialProviderRegistry;
use Konventix\Identity\Social\SteamProvider;
use Konventix\Identity\Social\UnknownSocialProvider;

/**
 * The registry is the seam that keeps the SPEC's promise: Steam is *a* driver,
 * not the special case LanCore made of it. It is also the route's whitelist —
 * an unknown `{provider}` must die here rather than reaching Socialite, which
 * would surface a driver exception instead of a 404.
 */
it('resolves the steam driver by its key', function (): void {
    $registry = app(SocialProviderRegistry::class);

    expect($registry->get('steam'))->toBeInstanceOf(SteamProvider::class)
        ->and($registry->has('steam'))->toBeTrue();
});

it('refuses a provider key nobody registered', function (): void {
    $registry = app(SocialProviderRegistry::class);

    expect($registry->has('discord'))->toBeFalse();

    $registry->get('discord');
})->throws(UnknownSocialProvider::class);

it('lists exactly the registered providers', function (): void {
    $keys = array_map(
        fn (SocialProvider $provider): string => $provider->key(),
        app(SocialProviderRegistry::class)->all(),
    );

    // Steam only, at release: D-IDN-01 as amended. Google and Discord were
    // dropped rather than deferred, and this asserts the drop.
    expect($keys)->toBe(['steam']);
});
