<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * An admission's lifecycle state (SPEC.md "Owns" — `Admission`).
 *
 * `Issued` is where `IssueAdmissionsForRegistration` leaves every admission
 * it creates — Phase 1 never assigns a holder, so `Assigned` exists in the
 * catalogue but nothing produces it yet (TODO.md Phase 2 backlog: holder
 * assignment). `Revoked` is written by `RevokeAdmissionsForRegistration` when
 * the registration behind an admission is cancelled (ADR-0057 point 6) —
 * never by a payment event, which ADR-0005 forbids the door to see. `Expired`
 * is still catalogued for `EvaluateAdmissionValidity` with no producing
 * Action.
 */
enum AdmissionState: string
{
    case Issued = 'issued';
    case Assigned = 'assigned';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
