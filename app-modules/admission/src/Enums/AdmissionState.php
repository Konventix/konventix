<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * An admission's lifecycle state (SPEC.md "Owns" — `Admission`).
 *
 * `Issued` is where `IssueAdmissionsForRegistration` leaves every admission
 * it creates — Phase 1 never assigns a holder, so `Assigned` exists in the
 * catalogue but nothing produces it yet (TODO.md Phase 2 backlog: holder
 * assignment). `Revoked` and `Expired` are likewise catalogued for
 * `EvaluateAdmissionValidity` but have no producing Action yet.
 */
enum AdmissionState: string
{
    case Issued = 'issued';
    case Assigned = 'assigned';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
