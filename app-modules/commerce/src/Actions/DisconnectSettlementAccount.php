<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Models\SettlementAccount;

/**
 * Ends an account's tenure as the brand's settlement route. The row stays —
 * refunds against payments it settled still need its identity — but the
 * brand falls back to `manual` until something else is connected
 * (ADR-0007's honest default).
 */
final class DisconnectSettlementAccount
{
    public function handle(SettlementAccount $account): SettlementAccount
    {
        if ($account->disconnected_at === null) {
            $account->forceFill(['disconnected_at' => now()])->save();
        }

        return $account;
    }
}
