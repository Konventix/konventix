<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when a subject agrees to a policy version (SPEC "Public contract").
 * Payload is scalars only — never the Consent model (ADR-0004).
 *
 * `captureContext` is the coarse label of the surface that captured the
 * agreement — the same value the consent row stores. It was added for the
 * grants counter (ADR-0066 spec §10), which has to be comparable with
 * `ConsentPromptRaised`'s surface for the derived refusal rate to mean
 * anything (D-CPL-17); `ComplianceMetrics::surfaceForCaptureContext()` is
 * the fold onto that bounded set.
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
        public readonly string $captureContext = '',
    ) {}
}
