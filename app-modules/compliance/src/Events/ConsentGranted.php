<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when a subject agrees to a policy version (SPEC "Public contract").
 * Payload is scalars only — never the Consent model (ADR-0004).
 */
final class ConsentGranted
{
    public function __construct(
        public readonly string $consentId,
        public readonly string $subjectId,
        public readonly string $policyId,
        public readonly string $versionId,
        public readonly string $brandId,
        public readonly string $grantedAt,
    ) {}
}
