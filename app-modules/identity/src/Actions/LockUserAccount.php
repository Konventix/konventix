<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\UserAccountLocked;
use Konventix\Identity\Models\User;

/**
 * Locks an account: `AuthenticateUser` refuses locked accounts at login,
 * so the lock takes effect at the next authentication attempt. Idempotent —
 * locking a locked account changes nothing and emits nothing.
 */
final class LockUserAccount
{
    public function handle(User $user, string $reasonCode): User
    {
        return DB::transaction(function () use ($user, $reasonCode): User {
            if ($user->state === AccountState::Locked) {
                return $user;
            }

            $user->forceFill(['state' => AccountState::Locked])->save();

            event(new UserAccountLocked(
                userId: $user->id,
                reasonCode: $reasonCode,
                lockedAt: now()->toIso8601String(),
            ));

            return $user;
        });
    }
}
