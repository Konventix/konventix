<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * The capability catalogue this module owns (ADR-0013, SPEC.md
 * "Capabilities"). This is the source of truth for what Registration can
 * grant; role → permission assignment is per-brand data, not code.
 *
 * Picked up by `permissions:sync` (ADR-0013); add a case and re-sync — never
 * create permission rows by hand.
 */
enum Permission: string
{
    case ViewRegistrations = 'registration.view-registrations';
    case ManageRegistrationTypes = 'registration.manage-registration-types';
    case ManageQuotas = 'registration.manage-quotas';
    case ApproveRegistrations = 'registration.approve-registrations';
    case RejectRegistrations = 'registration.reject-registrations';
    case CancelRegistrations = 'registration.cancel-registrations';

    /**
     * D-REG-15: take units off a confirmed registration and send the money
     * for them back. Deliberately **not** part of `registration.desk`
     * (ADR-0045): the desk approves, rejects and cancels, but a partial
     * cancellation moves money through Commerce without the operator ever
     * opening the order, so it is granted on purpose or not at all.
     */
    case ReduceRegistrationQuantity = 'registration.reduce-registration-quantity';
    /**
     * D-REG-24 / ADR-0066: defining what a reduced fare rests on — the
     * condition, its wording, the document the door will ask for. A product
     * job, next to `ManageRegistrationTypes`, and deliberately **not** part
     * of `registration.desk`: the desk sells and judges, it does not write
     * the terms of the offer.
     */
    case ManageEntryConditions = 'registration.manage-entry-conditions';

    /**
     * D-REG-24 / ADR-0066: judging one declaration — verify, refuse or waive
     * it. The other half of the same feature and a different job, which is
     * why it is a second capability: the worklist an `operator_checked`
     * condition fills is desk work, so `registration.desk` carries this one.
     */
    case VerifyEntryConditions = 'registration.verify-entry-conditions';

    case IssueInvitationTokens = 'registration.issue-invitation-tokens';
    case ExportRegistrations = 'registration.export-registrations';
}
