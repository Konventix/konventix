<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * The capability catalogue this module owns (ADR-0013, SPEC.md
 * "Capabilities"). This is the source of truth for what Admission can grant;
 * role → permission assignment is per-brand data, not code.
 *
 * Door staff need a narrow set — `ScanCheckIn` and `PrintBadge` — and
 * nothing else, which is why those two are separate cases from the
 * management capabilities rather than folded into a single "manage
 * admissions" grant (SPEC.md "Capabilities").
 *
 * Picked up by `permissions:sync` (ADR-0013); add a case and re-sync — never
 * create permission rows by hand.
 */
enum Permission: string
{
    case ViewAdmissions = 'admission.view-admissions';
    case IssueAdmissions = 'admission.issue-admissions';
    case RevokeAdmissions = 'admission.revoke-admissions';
    case AssignHolder = 'admission.assign-holder';
    case ReassignHolder = 'admission.reassign-holder';
    case ManageCheckpoints = 'admission.manage-checkpoints';
    case ViewCheckInLog = 'admission.view-check-in-log';
    case ScanCheckIn = 'admission.scan-check-in';
    case PrintBadge = 'admission.print-badge';
    // Designing a badge is an organizer act; printing one is a door act.
    // Keeping them apart is why door staff can hold PrintBadge without also
    // being able to redesign the event's badges (D-ADM-05).
    case ManageBadgeTemplates = 'admission.manage-badge-templates';
}
