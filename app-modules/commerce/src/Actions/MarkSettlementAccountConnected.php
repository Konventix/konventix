<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Events\SettlementAccountConnected;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * The second leg of a hosted connect flow (Stripe): the row was created
 * unconnected when onboarding started, and becomes the brand's live
 * account when the operator returns. Idempotent — Stripe's return URL can
 * be revisited, and a second `SettlementAccountConnected` would tell
 * consumers a switch happened that did not.
 */
final class MarkSettlementAccountConnected
{
    public function handle(SettlementAccount $account): SettlementAccount
    {
        if ($account->connected_at !== null) {
            return $account;
        }

        $account->forceFill(['connected_at' => now()])->save();

        event(new SettlementAccountConnected(
            settlementAccountId: $account->id,
            brandId: $account->brand_id,
            provider: $account->provider,
            connectedAt: $account->connected_at->toIso8601String(),
        ));

        return $account;
    }
}
