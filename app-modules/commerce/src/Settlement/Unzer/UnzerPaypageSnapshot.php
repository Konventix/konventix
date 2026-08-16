<?php

declare(strict_types=1);

namespace Konventix\Commerce\Settlement\Unzer;

/**
 * What the adapter needs back from a created Unzer Paypage: its id
 * (stored as the payment's `external_id`) and the hosted payment page URL.
 */
final readonly class UnzerPaypageSnapshot
{
    public function __construct(
        public string $id,
        public string $redirectUrl,
    ) {}
}
