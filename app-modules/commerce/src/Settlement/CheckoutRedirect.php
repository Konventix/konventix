<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement;

/**
 * Where a hosted checkout sends the payer (ADR-0039): the pending
 * `Payment` row's ULID and the provider's payment-page URL.
 */
final readonly class CheckoutRedirect
{
    public function __construct(
        public string $paymentId,
        public string $url,
    ) {}
}
