<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * The registration lifecycle state — independent of `PaymentStatus`
 * (ADR-0005, SPEC.md "Two independent status fields").
 *
 * Transitions are modelled as Actions in `src/Actions/`, each guarding which
 * states it may legally start from. Controllers never write `status` directly.
 */
enum RegistrationStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
