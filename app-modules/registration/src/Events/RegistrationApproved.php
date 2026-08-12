<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * A reviewer approved a registration on an `approval: required` type
 * (SPEC.md "Public contract" table). Emitted by
 * `Konventix\Registration\Actions\ApproveRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class RegistrationApproved
{
    public function __construct(
        public readonly string $registrationId,
        public readonly string $approvedByUserId,
        public readonly string $approvedAt,
    ) {}
}
