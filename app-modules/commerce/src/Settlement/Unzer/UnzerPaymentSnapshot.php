<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

/**
 * The fetch-back view of one Unzer payment (ADR-0039): Unzer's payment id,
 * its state name (`pending` / `completed` / `canceled` / …), and the
 * `orderId` we stamped at Paypage creation — our `Payment` ULID, which is
 * what correlates an account-level notification back to a row.
 */
final readonly class UnzerPaymentSnapshot
{
    public function __construct(
        public string $id,
        public string $state,
        public ?string $orderId,
    ) {}
}
