<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use RuntimeException;

/**
 * Resolves which `SettlementAdapter` handles a brand's money (ADR-0007).
 *
 * Until now there was a contract and two implementations but nothing that
 * chose between them — callers instantiated an adapter directly, which works
 * for exactly as long as there is only one real provider. Stripe and Mollie
 * (Slice 7b) need a seam, and adding it now means those adapters are a
 * registration rather than a rewrite of every call site.
 *
 * Keyed by `SettlementAccount::$provider`.
 *
 * Registered as a singleton in `CommerceServiceProvider`; adapters are
 * resolved from the container so they can take their own dependencies.
 */
final class SettlementAdapterRegistry
{
    /** @var array<string, class-string<SettlementAdapter>> */
    private array $adapters = [];

    /**
     * @param  class-string<SettlementAdapter>  $adapter
     */
    public function register(string $provider, string $adapter): void
    {
        $this->adapters[$provider] = $adapter;
    }

    /**
     * @return array<string, class-string<SettlementAdapter>>
     */
    public function registered(): array
    {
        return $this->adapters;
    }

    public function forProvider(string $provider): SettlementAdapter
    {
        $adapter = $this->adapters[$provider] ?? null;

        if ($adapter === null) {
            throw new RuntimeException(sprintf(
                'No settlement adapter is registered for provider [%s]. Register one in a '
                .'ServiceProvider before a brand can settle through it (ADR-0007).',
                $provider,
            ));
        }

        return app($adapter);
    }
}
