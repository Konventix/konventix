<?php

declare(strict_types=1);

namespace Konventix\Commerce\Actions;

use Konventix\Commerce\Data\UpdateSettlementAccountLabelData;
use Konventix\Commerce\Models\SettlementAccount;
use Spatie\LaravelData\Optional;

/**
 * Sets the operator's own words for one account: the checkout button
 * (ADR-0046) and, for Stripe, the payer's card statement (ADR-0058 point 3).
 * A blank value is not cleared to a special one — it is already what
 * `SettlementAccount::checkoutLabel()` and Stripe itself treat as "use the
 * default".
 *
 * An *omitted* descriptor is a third answer again: it leaves the stored one
 * standing, so editing the label alone cannot wipe it.
 */
final class UpdateSettlementAccountLabel
{
    public function handle(SettlementAccount $account, UpdateSettlementAccountLabelData $data): SettlementAccount
    {
        $attributes = ['checkout_label' => $data->checkoutLabel];

        $suffix = $data->statementDescriptorSuffix;

        // Two guards, for two different reasons. `Optional` means the field
        // was not sent at all — not editing it is not the same as clearing
        // it. And the column is on every row while only Stripe reads it, so
        // parking a descriptor on a Mollie or Unzer account would be dead
        // data that reads like a setting.
        if (! $suffix instanceof Optional && $account->provider === SettlementAccount::PROVIDER_STRIPE) {
            $attributes['statement_descriptor_suffix'] = is_string($suffix) && trim($suffix) !== ''
                ? trim($suffix)
                : null;
        }

        $account->forceFill($attributes)->save();

        return $account;
    }
}
