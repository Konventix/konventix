<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Data\AdminUpdateUserData;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\User;

/**
 * Operator-side account edit. Same rules the self-service profile applies:
 * changing the email un-verifies it, and `UserProfileUpdated` carries the
 * current values so read models (Messaging's recipients, Registration's
 * registrants) stay in sync (ADR-0004).
 */
final class UpdateUserAccount
{
    public function handle(User $user, AdminUpdateUserData $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->fill([
                'name' => $data->name,
                'email' => $data->email,
                'locale' => $data->locale,
                'timezone' => $data->timezone,
            ]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $user->save();

            event(new UserProfileUpdated(
                userId: $user->id,
                email: $user->email,
                displayName: $user->name,
                username: $user->username,
                tagline: $user->tagline,
                statusEmoji: $user->status_emoji,
                socialLinks: $user->social_links ?? [],
                visibility: $user->profile_visibility->value,
                locale: $user->locale,
                updatedAt: now()->toIso8601String(),
            ));

            return $user;
        });
    }
}
