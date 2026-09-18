<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Konventix\Admission\Enums\Permission;
use Konventix\Admission\Models\Admission;
use Konventix\Platform\Permissions\PermissionHolders;

/**
 * Who may change an admission's holder (#292 §3, #16 point 3): the
 * **manager** — the registrant who bought it, `admissions.buyer_user_id`
 * — by ownership, or an **operator** by capability. Checked through
 * Platform's `PermissionHolders` so this module imports no User model
 * (ADR-0004), the way Resources' `AssignPlacesToRegistration` checks
 * `BookOnBehalf`. Unlike that Action there is no trusted null-user
 * operator path: nothing here runs from artisan.
 */
trait GuardsHolderActor
{
    public const string ACTOR_MANAGER = 'manager';

    public const string ACTOR_OPERATOR = 'operator';

    /**
     * @throws AuthorizationException
     */
    private function guardActor(Admission $admission, string $actorType, ?string $actorUserId, Permission $operatorPermission): void
    {
        if ($actorType === self::ACTOR_MANAGER) {
            if ($actorUserId === null || $admission->buyer_user_id !== $actorUserId) {
                throw new AuthorizationException('This ticket belongs to another registration.');
            }

            return;
        }

        if ($actorType === self::ACTOR_OPERATOR) {
            $holders = $actorUserId === null
                ? []
                : app(PermissionHolders::class)->userIdsWithPermission($admission->brand_id, $operatorPermission->value);

            if ($actorUserId === null || ! in_array($actorUserId, $holders, true)) {
                throw new AuthorizationException(sprintf('Changing a holder at the desk needs %s.', $operatorPermission->value));
            }

            return;
        }

        throw new AuthorizationException("Unknown actor type [{$actorType}].");
    }
}
