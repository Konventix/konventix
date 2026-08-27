<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Events\SettlementAccountConnected;
use Konventix\Commerce\Exceptions\BrandAlreadyHasLiveProviderException;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * Creates a brand's settlement account (ADR-0007). One live account per
 * provider — the database enforces it with a partial unique index, and this
 * action refuses first so the operator gets a reasoned message instead of a
 * constraint violation (ADR-0046): a brand may connect several providers at
 * once, but never two live accounts of the same one.
 *
 * Providers whose connection completes elsewhere (Stripe's hosted
 * onboarding) create the row unconnected and finish through
 * `MarkSettlementAccountConnected`; key-based providers (Mollie, Unzer)
 * connect in one step.
 */
final class ConnectSettlementAccount
{
    /**
     * @param  array<string, string>|null  $credentials
     */
    public function handle(
        string $brandId,
        string $provider,
        ?string $label = null,
        ?string $providerAccountId = null,
        ?array $credentials = null,
        bool $connected = true,
    ): SettlementAccount {
        return DB::transaction(function () use ($brandId, $provider, $label, $providerAccountId, $credentials, $connected): SettlementAccount {
            $live = SettlementAccount::liveAccountsFor($brandId)->firstWhere('provider', $provider);

            if ($live !== null) {
                throw BrandAlreadyHasLiveProviderException::for($brandId, $provider);
            }

            $account = new SettlementAccount([
                'provider' => $provider,
                'label' => $label,
                'provider_account_id' => $providerAccountId,
                'credentials' => $credentials,
                'connected_at' => $connected ? now() : null,
            ]);
            // brand_id explicit, not ambient context (ADR-0010): connecting
            // happens on the operator admin host.
            $account->forceFill(['brand_id' => $brandId])->save();

            if ($connected) {
                event(new SettlementAccountConnected(
                    settlementAccountId: $account->id,
                    brandId: $brandId,
                    provider: $provider,
                    connectedAt: $account->connected_at->toIso8601String(),
                ));
            }

            return $account;
        });
    }
}
