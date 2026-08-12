<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when a policy version becomes effective (SPEC "Public contract").
 * Payload is scalars only — never the PolicyVersion model (ADR-0004).
 */
final class PolicyPublished
{
    public function __construct(
        public readonly string $policyId,
        public readonly string $versionId,
        public readonly string $brandId,
        public readonly string $slug,
        public readonly string $effectiveAt,
        public readonly bool $requiresConsent,
    ) {}
}
