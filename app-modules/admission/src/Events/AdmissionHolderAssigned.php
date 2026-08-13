<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An initially unassigned admission got a named holder (ADR-0032). Emitted by
 * `Konventix\Admission\Actions\ClaimAdmission`.
 *
 * `viaClaimLink` distinguishes self-service claiming from an organizer
 * assigning a name at a desk. Both end in the same state; only one of them
 * means a person proved possession of a link, which is the distinction an
 * audit of "how did this admission get its holder" needs.
 *
 * Payload is scalars/ULIDs only (ADR-0004) — a user ULID, never a name or an
 * email.
 */
final readonly class AdmissionHolderAssigned
{
    public function __construct(
        public string $admissionId,
        public string $registrationId,
        public string $eventId,
        public string $brandId,
        public string $holderUserId,
        public bool $viaClaimLink,
        public string $assignedAt,
    ) {}
}
