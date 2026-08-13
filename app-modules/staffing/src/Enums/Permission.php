<?php

declare(strict_types=1);

namespace Konventix\Staffing\Enums;

/**
 * Staffing's capability catalogue (ADR-0013). Picked up by
 * `permissions:sync`, which walks every module's `Enums\Permission`.
 *
 * The split that matters is the last two: managing *other people's*
 * assignments is a lead's power, while claiming and releasing *your own*
 * shift is what self-service means (D-STF-01). A volunteer signing up for a
 * shift must not thereby be able to un-roster someone else.
 */
enum Permission: string
{
    case ViewRoster = 'staffing.view-roster';
    case ManageTeams = 'staffing.manage-teams';
    case ManageShiftRoles = 'staffing.manage-shift-roles';
    case ManageShifts = 'staffing.manage-shifts';
    case ManageAssignments = 'staffing.manage-assignments';
    case ClaimOwnShifts = 'staffing.claim-own-shifts';
}
