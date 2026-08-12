<?php

declare(strict_types=1);

namespace Konventix\Identity\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Identity's capability catalog (ADR-0013). Acting on one's own account is a
 * policy question, not a capability — these govern administering OTHER
 * people's accounts.
 */
#[TypeScript]
enum Permission: string
{
    case ViewUsers = 'identity.view-users';
    case ManageUsers = 'identity.manage-users';
    case LockUser = 'identity.lock-user';
    case UnlockUser = 'identity.unlock-user';
    case ResetUserTwoFactor = 'identity.reset-user-two-factor';
    case ViewUserSessions = 'identity.view-user-sessions';
    case RevokeUserSessions = 'identity.revoke-user-sessions';
    case ManageSocialProviders = 'identity.manage-social-providers';
    case ImpersonateUser = 'identity.impersonate-user';
}
