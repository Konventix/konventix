<?php

declare(strict_types=1);

namespace Konventix\Resources\Events;

/**
 * A pick lost the race for a place: the exclusion constraint (23P01) or a
 * deadlock (40P01) refused the hold inside `AssignPlacesToRegistration`,
 * nothing was written, and the registrant was asked to pick again.
 *
 * Internal to Resources — an observability signal, not part of the
 * cross-module contract (SPEC.md "Internal events"). No place id: the
 * refusal is counted per brand, never per seat or person.
 *
 * Payload is scalars/ULIDs only (ADR-0004). `reason` is `unavailable` or
 * `deadlock`.
 */
final readonly class ResourceBookingRefused
{
    public function __construct(
        public string $brandId,
        public string $eventId,
        public string $registrationId,
        public string $reason,
    ) {}
}
