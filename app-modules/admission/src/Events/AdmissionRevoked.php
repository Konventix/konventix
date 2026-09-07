<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An admission stops being valid before its window ends (SPEC.md "Public
 * contract" table, proposed row). Emitted when Admission consumes
 * `RegistrationCancelled` and moves the registration's admissions to
 * `AdmissionState::Revoked`, feeding the entrance delta feed of ADR-0033.
 *
 * **Admission revokes only on `RegistrationCancelled`, never on a money
 * event.** ADR-0057 point 6: no listener here reacts to `PaymentReversed` —
 * that would be the door asking "is it paid?" through the back door, which
 * ADR-0005 forbids and lessons-from-lancore #12 warns against. A person
 * already checked in keeps the admission regardless; a reversed-but-unpaid
 * order only reaches this event once Registration has actually cancelled.
 *
 * `registrationId` is nullable since D-STF-01: a crew admission has no
 * registration behind it and is never revoked by this path.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 */
final readonly class AdmissionRevoked
{
    public function __construct(
        public string $admissionId,
        public ?string $registrationId,
        public string $eventId,
        public string $brandId,
        public ?string $revokedByUserId,
        public string $reasonCode,
        public string $revokedAt,
    ) {}
}
