<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The independent payment status field moved (SPEC.md "Public contract",
 * "Two independent status fields") — payment status never implies
 * registration status, and this event says nothing about confirmation.
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationPaymentStatusChanged
{
    public function __construct(
        public string $registrationId,
        public string $brandId,
        public string $previousStatus,
        public string $status,
        public string $changedAt,
    ) {}
}
