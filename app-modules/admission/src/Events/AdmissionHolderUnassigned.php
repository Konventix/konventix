<?php

declare(strict_types=1);

namespace Konventix\Admission\Events;

/**
 * An admission lost its holder without losing its existence (#292, #73
 * "Umschreibung"): the buyer took it back, or an organizer cleared it at
 * the desk. The row stays, its seat stays (Resources keeps a bound place on
 * the admission, not on the person), and whoever is named next inherits
 * both. Refused after check-in, so a consumer never hears this for a
 * person who is already inside.
 *
 * Scalars only (ADR-0004).
 */
final readonly class AdmissionHolderUnassigned
{
    public function __construct(
        public string $admissionId,
        public ?string $registrationId,
        public string $eventId,
        public string $brandId,
        public string $previousHolderUserId,
        public ?string $unassignedByUserId,
        public string $reasonCode,
        public string $unassignedAt,
    ) {}
}
