<?php

declare(strict_types=1);

namespace Konventix\Support\Queries;

use Konventix\Platform\Enums\Permission as PlatformPermission;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Permissions\PermissionHolders;
use Konventix\Support\Enums\Permission;

/**
 * Who may be handed a report (design §9.2).
 *
 * The same rule the notice of §7 already applies, stated once so the
 * assignee picker and `NotifyOperatorsOnRequestOpened` cannot drift: a
 * report filed on a brand host belongs to the `support.triage-requests`
 * holders **of that brand**, and one filed on the operator console — which
 * has no brand to hold a capability on (ADR-0063) — belongs to the accounts
 * that can reach the console at all (`platform.access-admin`, D-SUP-12).
 *
 * The answer is a list of ULIDs and stays one. Naming those accounts would
 * mean reading Identity's table, which this module may not do (ADR-0004);
 * Platform's own role editor prints ULIDs for exactly the same reason, and
 * a Platform-maintained identity read model is the sanctioned way to add
 * names later (`RoleAssignmentController`, Platform's TODO.md).
 *
 * `OperatorContext` is resolved per call rather than injected: it is a
 * scoped binding, and whatever holds this class may outlive the request
 * under Octane (`.ai/rules/providers.md`).
 */
final class TriageHolders
{
    public function __construct(private readonly PermissionHolders $permissionHolders) {}

    /**
     * @return list<string>
     */
    public function forBrand(?string $brandId): array
    {
        if ($brandId !== null) {
            return $this->permissionHolders->userIdsWithPermission(
                $brandId,
                Permission::TriageRequests->value,
            );
        }

        $operatorId = app(OperatorContext::class)->id();

        if ($operatorId === null) {
            return [];
        }

        return $this->permissionHolders->userIdsWithPermission(
            $operatorId,
            PlatformPermission::AccessAdmin->value,
        );
    }
}
