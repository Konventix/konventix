<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * What a single entry in a `RegistrationType`'s `grants` collection produces
 * once a registration confirms (ADR-0005, SPEC.md "Grant definitions").
 *
 * `Admission` grants are what `Konventix\Admission`'s listener reacts to
 * (one `Admission` row per unit of `quantity`). `ResourceClaim` grants are
 * fulfilled first-fit by Resources (one booking of `resourceTypeId`).
 * `Seating` grants (seating design §7.1) become a `resource_claims` row in
 * Resources that the registrant places on `quantity` places out of
 * `resourceTypeIds`, in `seatingMode`, after confirmation.
 */
enum GrantKind: string
{
    case Admission = 'admission';
    case ResourceClaim = 'resource_claim';
    case Seating = 'seating';
}
