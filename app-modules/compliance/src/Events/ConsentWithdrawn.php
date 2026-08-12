<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when a subject withdraws agreement (SPEC "Public contract").
 * Payload is scalars only — never the Consent model (ADR-0004).
 */
final class ConsentWithdrawn
{
    public function __construct(
        public readonly string $consentId,
        public readonly string $subjectId,
        public readonly string $policyId,
        public readonly string $withdrawnAt,
    ) {}
}
