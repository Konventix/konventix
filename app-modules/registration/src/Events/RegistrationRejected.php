<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A reviewer rejected a registration (SPEC.md "Public contract" table).
 * Emitted by `Konventix\Registration\Actions\RejectRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class RegistrationRejected
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $rejectedByUserId,
        public readonly string $reasonCode,
        public readonly string $rejectedAt,
    ) {}
}
