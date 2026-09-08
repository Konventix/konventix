<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Commerce\Events\SettlementAccountConnected;
use Konventix\Commerce\Exceptions\BrandAlreadyHasLiveProviderException;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * Creates a brand's settlement account (ADR-0007). One live account per
 * provider — the database enforces it with a partial unique index on
 * `(brand_id, provider)`, and this action refuses first so the operator
 * gets a reasoned message instead of a constraint violation (ADR-0046): a
 * brand may connect several providers at once, but never two live accounts
 * of the same one. The index names no account id, so a Stripe row carrying
 * none (ADR-0058 point 3) is as unique as any other.
 *
 * Every provider connects in one step now: Mollie and Unzer against the
 * brand's own key, Stripe as a plain enablement flag on the account this
 * instance already holds — the `$connected = false` half that used to wait
 * for Stripe's hosted onboarding has no caller left and is gone with it.
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
        ?string $checkoutLabel = null,
        ?string $statementDescriptorSuffix = null,
    ): SettlementAccount {
        return DB::transaction(function () use ($brandId, $provider, $label, $providerAccountId, $credentials, $checkoutLabel, $statementDescriptorSuffix): SettlementAccount {
            $live = SettlementAccount::liveAccountsFor($brandId)->firstWhere('provider', $provider);

            if ($live !== null) {
                throw BrandAlreadyHasLiveProviderException::for($brandId, $provider);
            }

            $account = new SettlementAccount([
                'provider' => $provider,
                'label' => $label,
                'checkout_label' => $checkoutLabel,
                'statement_descriptor_suffix' => $statementDescriptorSuffix,
                'provider_account_id' => $providerAccountId,
                'credentials' => $credentials,
                'connected_at' => now(),
            ]);
            // brand_id explicit, not ambient context (ADR-0010): connecting
            // happens on the operator admin host.
            $account->forceFill(['brand_id' => $brandId])->save();

            event(new SettlementAccountConnected(
                settlementAccountId: $account->id,
                brandId: $brandId,
                provider: $provider,
                connectedAt: $account->connected_at->toIso8601String(),
            ));

            return $account;
        });
    }
}
