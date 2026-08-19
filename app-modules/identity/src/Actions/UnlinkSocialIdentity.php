<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Identity\Events\SocialIdentityUnlinked;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;

/**
 * Detach a provider identity from an account.
 *
 * The whole reason this is an Action and not a `delete()` call in a controller
 * is the lockout guard below.
 */
final class UnlinkSocialIdentity
{
    /**
     * @throws ValidationException when unlinking would leave no way into the account
     */
    public function handle(User $user, string $provider): void
    {
        DB::transaction(function () use ($user, $provider): void {
            /** @var SocialIdentity|null $identity */
            $identity = $user->socialIdentities()
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();

            if ($identity === null) {
                return;
            }

            $this->refuseIfLastWayIn($user, $provider);

            $identity->delete();

            event(new SocialIdentityUnlinked(
                userId: (string) $user->getKey(),
                provider: $provider,
            ));
        });
    }

    /**
     * An account reached only through one provider, with no password to fall
     * back on, would become unreachable the moment that provider is unlinked —
     * and unreachable permanently, because password reset delivers to an
     * address the person can no longer prove they own by logging in.
     *
     * So the last way in is not removable. The person sets a password first;
     * the screen says so.
     *
     * @throws ValidationException
     */
    private function refuseIfLastWayIn(User $user, string $provider): void
    {
        if ($user->hasPassword()) {
            return;
        }

        $others = $user->socialIdentities()
            ->where('provider', '!=', $provider)
            ->count();

        if ($others > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'provider' => [__('Set a password first — this is the only way into your account.')],
        ]);
    }
}
