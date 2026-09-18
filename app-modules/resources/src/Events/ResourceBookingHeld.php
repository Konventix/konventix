<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A provisional hold was placed on a resource (SPEC.md "Public contract").
 *
 * Deliberately distinct from `ResourceBookingConfirmed`: a hold expires and
 * may never become firm, so nothing irreversible may react to it. Resources'
 * SPEC settles the naming for exactly this reason — "a hold is provisional and
 * must never reach a badge".
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 *
 * Since seating S0 the payload also names the event, the type, the
 * registration and claim it belongs to and the resource's label, so a
 * consumer never has to join back into this module's tables (ADR-0004).
 */
final readonly class ResourceBookingHeld
{
    /**
     * `holdExpiresAt` is nullable: a type with `hold_minutes` 0 places a
     * hold with no clock (ADR-0060's second hold kind — confirmed in the
     * same transaction, never swept). `ref` is null until plans exist (S1).
     */
    public function __construct(
        public string $bookingId,
        public string $resourceId,
        public string $brandId,
        public string $holderType,
        public string $holderId,
        public int $quantity,
        public string $startsAt,
        public string $endsAt,
        public ?string $holdExpiresAt,
        public string $eventId,
        public string $resourceTypeId,
        public ?string $registrationId,
        public ?string $claimId,
        public string $label,
        public ?string $ref,
        /** The admission this place seats (#292 §6.2); null for a registration-level or operator booking. Additive. */
        public ?string $admissionId = null,
    ) {}
}
