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
    case IssueInvitationTokens = 'registration.issue-invitation-tokens';
    case ExportRegistrations = 'registration.export-registrations';
}
