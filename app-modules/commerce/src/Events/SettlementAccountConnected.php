<?php

declare(strict_types=1);

namespace Konventix\Commerce\Events;

/**
 * A brand finished connecting its own PSP account (ADR-0007, SPEC.md
 * "Public contract" table) — from this moment the brand's orders settle
 * through that provider instead of `manual`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class SettlementAccountConnected
{
    public function __construct(
        public readonly string $settlementAccountId,
        public readonly string $brandId,
        public readonly string $provider,
        public readonly string $connectedAt,
    ) {}
}
