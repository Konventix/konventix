<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * Emitted by `Actions\RemoveCartLine` when the line a person removed was a
 * registration line — a product line releases its stock silently instead,
 * since nothing outside Commerce needs to know about that. Registration
 * listens for this to cancel the still-pending registration the line was
 * holding (ADR-0047 point 3).
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final readonly class CartLineRemoved
{
    public function __construct(
        public string $cartId,
        public string $brandId,
        public string $kind,
        public string $refId,
        public ?string $requestKey,
        public string $removedAt,
    ) {}
}
