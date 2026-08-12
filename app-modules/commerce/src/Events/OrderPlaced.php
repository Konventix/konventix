<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * An order was created from a settlement request (SPEC.md "Public
 * contract"). Scalars only (ADR-0004).
 */
final readonly class OrderPlaced
{
    public function __construct(
        public string $orderId,
        public string $brandId,
        public string $settlesType,
        public string $settlesId,
        public string $totalNet,
        public string $totalGross,
        public string $currency,
        public string $placedAt,
    ) {}
}
