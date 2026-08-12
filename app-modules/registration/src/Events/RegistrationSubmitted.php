<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A registration form was accepted and persisted (SPEC.md "Public contract"
 * table). Emitted by `Konventix\Registration\Actions\SubmitRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class RegistrationSubmitted
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $registrationTypeId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $registrantUserId,
        public readonly string $settlement,
        public readonly string $confirmationGate,
        public readonly string $submittedAt,
    ) {}
}
