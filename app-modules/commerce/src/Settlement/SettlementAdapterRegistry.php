<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

use Konventix\Commerce\Models\SettlementAccount;
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
 * Keyed by `SettlementAccount::$provider`. A brand with no connected account
 * resolves to `manual` — booking a bank transfer by hand is the honest
 * default and the only mode the definition of done actually requires.
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

    /**
     * The adapter for a brand, via its live account.
     */
    public function forBrand(string $brandId): SettlementAdapter
    {
        $account = SettlementAccount::liveFor($brandId);

        return $this->forProvider($account === null ? SettlementAccount::PROVIDER_MANUAL : $account->provider);
    }

    /**
     * The account id to pass to the adapter — the live account's ULID, or the
     * `manual` sentinel when a brand has connected nothing. Callers must never
     * invent this: `SettlementAdapter` refuses to act without an account id
     * precisely so an adapter cannot move money for an unidentified party.
     */
    public function accountIdForBrand(string $brandId): string
    {
        $account = SettlementAccount::liveFor($brandId);

        return $account === null ? SettlementAccount::PROVIDER_MANUAL : $account->id;
    }
}
