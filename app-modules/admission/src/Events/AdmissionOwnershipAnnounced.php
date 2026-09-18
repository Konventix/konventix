<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An admission's current shape, restated for backfill (#292): who bought
 * it, who holds it, and whether it still stands. Resources' own read model
 * of admissions (`resource_admissions`, seating design §6.1) is fed by
 * `AdmissionIssued`, `AdmissionHolderAssigned`, `AdmissionHolderUnassigned`
 * and `AdmissionRevoked` going forward; every admission that existed before
 * that read model did needs one announcement to appear in it.
 *
 * Its own event rather than a replay of `AdmissionIssued`, for the reason
 * `RegistrationOwnershipAnnounced` exists: `AdmissionIssued` also drives
 * the metrics recorders and the entrance profile, and re-emitting it for
 * every admission ever issued would count each of them again. This carries
 * only what a *projection* needs. **Only a projection may ever listen to
 * it** — a test pins that.
 *
 * Scalars only (ADR-0004).
 */
final readonly class AdmissionOwnershipAnnounced
{
    public function __construct(
        public string $admissionId,
        public ?string $registrationId,
        public string $eventId,
        public string $brandId,
        public ?string $holderUserId,
        public ?string $buyerUserId,
        public string $state,
    ) {}
}
