<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A booking became firm — **the event other modules may act on**.
 *
 * Resources' SPEC settles that this, not `ResourceBookingCreated`, is the
 * name in the contract, because only a confirmed booking is real enough to
 * print on a badge or bill for.
 *
 * Payload is scalars/ULIDs only (ADR-0004).
 *
 * Since seating S0 the payload also names the event, the type, the
 * registration and claim it belongs to and the resource's label, so a
 * consumer never has to join back into this module's tables (ADR-0004).
 *
 * `holderId` is nullable for symmetry with `ResourceBookingReleased`
 * (2026-09-10, GDPR follow-up), even though today's only caller,
 * `ConfirmResourceBooking`, always runs in the same transaction as a fresh
 * hold and so can never actually read back a nulled holder — narrowing one
 * of the pair back to `string` would only be a trap for whoever adds a
 * second caller later.
 */
final readonly class ResourceBookingConfirmed
{
    public function __construct(
        public string $bookingId,
        public string $resourceId,
        public string $brandId,
        public string $holderType,
        public ?string $holderId,
        public ?string $registrationId,
        public int $quantity,
        public string $startsAt,
        public string $endsAt,
        public string $confirmedAt,
        public string $eventId,
        public string $resourceTypeId,
        public ?string $claimId,
        public string $label,
        public ?string $ref,
        /** The admission this place seats (#292 §6.2); null for a registration-level or operator booking. Additive. */
        public ?string $admissionId = null,
    ) {}
}
