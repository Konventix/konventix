<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Data\UpdateSettlementAccountLabelData;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * Sets the operator's own words for one account's checkout button
 * (ADR-0046). A blank label is not cleared to a special value — it is
 * already what `SettlementAccount::checkoutLabel()` treats as "use the
 * adapter's default".
 */
final class UpdateSettlementAccountLabel
{
    public function handle(SettlementAccount $account, UpdateSettlementAccountLabelData $data): SettlementAccount
    {
        $account->forceFill(['checkout_label' => $data->checkoutLabel])->save();

        return $account;
    }
}
