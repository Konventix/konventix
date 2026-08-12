<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * The contractual act was cancelled by organizer or registrant (SPEC.md
 * "Public contract" table). Emitted by
 * `Konventix\Registration\Actions\CancelRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class RegistrationCancelled
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $cancelledByUserId,
        public readonly string $reasonCode,
        public readonly string $cancelledAt,
    ) {}
}
