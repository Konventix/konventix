<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

/**
 * Emitted when withdrawing a consent opens a DataSubjectRequest instead of
 * withdrawing immediately — the policy's lawful basis is not consent, so
 * Art. 7(3) does not apply and the operator decides (ADR-0050 §7/§8).
 *
 * Payload is scalars only, never a model (ADR-0004).
 */
final class ConsentWithdrawalRequested
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $consentId,
        public readonly string $subjectId,
        public readonly string $policyId,
    ) {}
}
