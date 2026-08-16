<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Mollie;

/**
 * What the adapter needs back from Mollie about one payment: Mollie's id
 * (`tr_…`), its current status, and — right after creation — the hosted
 * checkout URL to send the payer to.
 */
final readonly class MolliePaymentSnapshot
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $checkoutUrl = null,
    ) {}
}
