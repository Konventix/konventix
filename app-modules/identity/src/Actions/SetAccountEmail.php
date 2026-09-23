<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Models\User;

/**
 * Replace a placeholder address with the one the person typed (#293).
 *
 * Deliberately narrower than `ProfileController::update()`: it touches the
 * address alone, and only on an account that still carries a placeholder —
 * an account with a real address changes it on the profile form, where the
 * change is one edit among others. The address stays unverified until the
 * link is followed; the projection event goes out at once so Messaging's
 * read model already knows the real address when that link is sent.
 */
final class SetAccountEmail
{
    public function handle(User $user, string $email): void
    {
        DB::transaction(function () use ($user, $email): void {
            $user->forceFill([
                'email' => $email,
                'email_verified_at' => null,
            ])->save();

            event(new UserProfileUpdated(
                userId: $user->id,
                email: $user->email,
                displayName: $user->name,
                username: $user->username,
                tagline: $user->tagline,
                statusEmoji: $user->status_emoji,
                socialLinks: $user->social_links ?? [],
                visibility: $user->profile_visibility->value,
                listedInAttendees: $user->listed_in_attendees,
                locale: $user->locale ?? (string) config('app.locale'),
                updatedAt: now()->toIso8601String(),
            ));
        });

        $user->sendEmailVerificationNotification();
    }
}
