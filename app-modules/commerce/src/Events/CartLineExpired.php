<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Emitted by the expiry sweep (`Actions\ExpireCarts`) for each
 * registration-kind line a cart carried past its `expires_at` — product
 * lines release their stock silently instead, since nothing outside
 * Commerce needs to know about that. Registration listens for this to
 * cancel the still-pending registration the line was holding (ADR-0047
 * point 3, reusing the waitlist sweep's shape).
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class CartLineExpired
{
    public function __construct(
        public string $cartId,
        public string $brandId,
        public string $kind,
        public string $refId,
        public ?string $requestKey,
        public string $expiredAt,
    ) {}
}
