<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * Units returned to a quota after cancellation or rejection (SPEC.md "Public
 * contract" table). Emitted by
 * `Konventix\Registration\Actions\RejectRegistration` and
 * `Konventix\Registration\Actions\CancelRegistration`.
 */
final class QuotaReleased
{
    public function __construct(
        public readonly string $quotaId,
        public readonly int $releasedUnits,
        public readonly string $releasedAt,
    ) {}
}
