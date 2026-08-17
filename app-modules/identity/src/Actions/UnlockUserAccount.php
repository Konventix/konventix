<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Events\UserAccountUnlocked;
use Konventix\Identity\Models\User;

/**
 * Lifts an account lock. Idempotent — unlocking an active account changes
 * nothing and emits nothing.
 */
final class UnlockUserAccount
{
    public function handle(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            if ($user->state !== AccountState::Locked) {
                return $user;
            }

            $user->forceFill(['state' => AccountState::Active])->save();

            event(new UserAccountUnlocked(
                userId: $user->id,
                unlockedAt: now()->toIso8601String(),
            ));

            return $user;
        });
    }
}
