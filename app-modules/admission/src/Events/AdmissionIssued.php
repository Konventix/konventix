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
        // Nullable since D-STF-01: a crew admission comes from a shift
        // assignment, not a registration, and has no commercial act behind it.
        public readonly ?string $registrationId,
        public readonly string $eventId,
        public readonly string $brandId,
        public readonly string $role,
        public readonly ?string $holderUserId,
        public readonly string $issuedAt,
    ) {}
}
