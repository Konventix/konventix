<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

/**
 * Where a registration stands right now, restated for backfill.
 * `registration:announce-standing` emits it once for every registration, so
 * that a read model built after the registration was submitted, confirmed,
 * cancelled or rejected can learn its current state. The first consumer is
 * Competition's `competition_eligible_registrations`, the §5.1 entry gate
 * (competition design §5.1, D-CMP-05; final review fix wave B, item 2).
 *
 * The case it exists for is a registration that reached the database without
 * emitting anything: the hand-run Eventula migration writes rows directly,
 * and rows older than a projection were never seen by it. Such a registration
 * is invisible to Competition, so its registrant can never enter.
 *
 * This is its own event rather than a replay of `RegistrationSubmitted` and
 * `RegistrationConfirmed`. Those drive the confirmation mail and the
 * settlement listeners, so re-emitting them would mail every registrant and
 * re-run commerce, and `RegistrationRejected` is an `AuditableEvent` whose
 * replay would write a false "rejected" entry dated to the run. The same
 * reasoning produced `RegistrationOwnershipAnnounced`, `AdmissionOwnershipAnnounced`
 * and `SocialIdentityAnnounced`. **Only a projection may ever listen to it.**
 *
 * `confirmed` is true exactly when the registration's status is `confirmed`
 * now. A cancelled or rejected registration announces `false`, so a
 * consumer that learned of a confirmation earlier unlearns it.
 *
 * `registrationTypeId`, `registrationTypeName` and `status` were added for
 * Admission's registration summary (attendee-list design §3, §4.4, #329):
 * the ticket type label and the registration's status as the console shows
 * them. All three are trailing and optional (ADR-0004, additive), so an
 * emitter written before them still compiles; `status` is the
 * `RegistrationStatus` value, and a consumer that finds it null falls back
 * on the `$confirmed` flag — Admission's projection reads `confirmed` when
 * it is true and a non-committal `unconfirmed` when it is false.
 *
 * Scalars only (ADR-0004).
 */
final readonly class RegistrationStandingAnnounced
{
    public function __construct(
        public string $registrationId,
        public string $eventId,
        public string $brandId,
        public string $registrantUserId,
        public bool $confirmed,
        public ?string $registrationTypeId = null,
        public ?string $registrationTypeName = null,
        public ?string $status = null,
    ) {}
}
