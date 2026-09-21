<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when a subject withdraws agreement (SPEC "Public contract").
 * Payload is scalars only — never the Consent model (ADR-0004).
 *
 * `brandId` is nullable and trails the other fields because it was added for
 * the withdrawals counter (ADR-0066 spec §10). Null means the withdrawal was
 * recorded where no brand could be named — a console command, a queued
 * sweep — and the metric skips it rather than inventing an `unknown` series
 * that would never be cleared.
 */
final class ConsentWithdrawn
{
    public function __construct(
        public readonly string $consentId,
        public readonly string $subjectId,
        public readonly string $policyId,
        public readonly string $withdrawnAt,
        public readonly ?string $brandId = null,
    ) {}
}
