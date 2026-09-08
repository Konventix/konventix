<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

/**
 * The fetch-back view of one Unzer payment (ADR-0039): Unzer's payment id,
 * its state name (`pending` / `completed` / `canceled` / …), and the
 * `orderId` we stamped at Paypage creation — our `Payment` ULID, which is
 * what correlates an account-level notification back to a row.
 *
 * `chargebacks` are the payment's chargeback transactions (ADR-0057
 * point 7): Unzer moves the payment to state `chargeback` and says nothing
 * about which transaction caused it, so the controller reads them from the
 * fetched payment and keys the reversal row on the transaction id. Empty
 * for every payment that has none.
 */
final readonly class UnzerPaymentSnapshot
{
    /**
     * @param  list<array{id: string, amount: string, currency: string, date: ?string}>  $chargebacks
     */
    public function __construct(
        public string $id,
        public string $state,
        public ?string $orderId,
        public array $chargebacks = [],
    ) {}
}
