<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

/**
 * Every social driver this deployment offers, keyed by provider name.
 *
 * Two jobs, and the second is the security-relevant one:
 *
 * 1. It is what makes adding a provider a registration rather than a rewrite —
 *    the flow that turns an identity into a login never names Steam.
 * 2. It is the route's whitelist. `{provider}` is checked here before anything
 *    touches Socialite, so an unknown key becomes a 404 instead of a driver
 *    exception leaking the container's internals into a 500.
 *
 * Registered from `IdentityServiceProvider`, like every other module wiring
 * (ADR-0003).
 */
final class SocialProviderRegistry
{
    /** @var array<string, SocialProvider> */
    private array $providers = [];

    public function register(SocialProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @throws UnknownSocialProvider
     */
    public function get(string $key): SocialProvider
    {
        return $this->providers[$key]
            ?? throw new UnknownSocialProvider("No social provider is registered under [{$key}].");
    }

    /**
     * @return list<SocialProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
