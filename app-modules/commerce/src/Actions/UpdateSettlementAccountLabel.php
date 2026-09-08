<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Data\UpdateSettlementAccountLabelData;
use Konventix\Commerce\Models\SettlementAccount;

/**
 * Sets the operator's own words for one account: the checkout button
 * (ADR-0046) and, for Stripe, the payer's card statement (ADR-0058 point 3).
 * A blank value is not cleared to a special one — it is already what
 * `SettlementAccount::checkoutLabel()` and Stripe itself treat as "use the
 * default".
 */
final class UpdateSettlementAccountLabel
{
    public function handle(SettlementAccount $account, UpdateSettlementAccountLabelData $data): SettlementAccount
    {
        $attributes = ['checkout_label' => $data->checkoutLabel];

        // The column is on every row, but only Stripe reads it — parking a
        // descriptor on a Mollie or Unzer account would be dead data that
        // reads like a setting.
        if ($account->provider === SettlementAccount::PROVIDER_STRIPE) {
            $suffix = $data->statementDescriptorSuffix;

            $attributes['statement_descriptor_suffix'] = is_string($suffix) && trim($suffix) !== ''
                ? trim($suffix)
                : null;
        }

        $account->forceFill($attributes)->save();

        return $account;
    }
}
