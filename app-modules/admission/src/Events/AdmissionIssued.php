<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An admission was created from a confirmed registration's grants (SPEC.md
 * "Public contract" table). Emitted by
 * `Konventix\Admission\Actions\IssueAdmissionsForRegistration`.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — never an Eloquent model.
 */
final class AdmissionIssued
{
    public function __construct(
        public readonly string $admissionId,
        public readonly string $registrationId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $role,
        public readonly ?string $holderUserId,
        public readonly string $issuedAt,
    ) {}
}
