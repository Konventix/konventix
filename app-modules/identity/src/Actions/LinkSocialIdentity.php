<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Models\User;
use Konventix\Identity\Social\SocialIdentityAlreadyLinked;
use Konventix\Identity\Social\SocialIdentityData;

/**
 * Attach a verified provider identity to an account.
 *
 * Only ever called with a `SocialIdentityData` a driver produced, which is the
 * point: an instance of that object existing is the assertion that the
 * handshake succeeded, so this Action never has to ask whether the identity is
 * genuine. It decides only whether it may be attached *here*.
 *
 * Two refusals, both `SocialIdentityAlreadyLinked` and both answered before
 * the insert so neither can reach a unique index: the identity belongs to
 * another account (named by username — that class's docblock says why), or
 * this account already holds a different identity at the same provider.
 */
final class LinkSocialIdentity
{
    /**
     * @throws SocialIdentityAlreadyLinked when the identity belongs to somebody else, or this account already holds one at that provider
     */
    public function handle(User $user, SocialIdentityData $identity): SocialIdentity
    {
        return DB::transaction(function () use ($user, $identity): SocialIdentity {
            /** @var SocialIdentity|null $existing */
            $existing = SocialIdentity::query()
                // Eager, not lazy: `Model::shouldBeStrict()` refuses a lazy
                // `->user` on a row a query returned, and the holder's
                // username is what the refusal below names.
                ->with('user')
                ->where('provider', $identity->provider)
                ->where('provider_user_id', $identity->providerUserId)
                // Locked for the length of the transaction: two callbacks
                // racing on the same provider identity would otherwise both
                // read "unlinked" and one would die on the unique index with a
                // constraint violation instead of this Action's answer.
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->user_id === $user->getKey()) {
                    // Already linked here. Idempotent rather than an error: a
                    // double-submitted callback should leave the account in the
                    // state the person asked for.
                    return $existing;
                }

                /** @var User $holder */
                $holder = $existing->user;

                throw SocialIdentityAlreadyLinked::forUsername($identity->provider, $holder->username);
            }

            // One account holds at most one identity per provider — the
            // `(user_id, provider)` unique index says so, and this answers
            // before the index has to. Locked like the lookup above so a
            // racing second callback reads this row rather than dying on
            // the index.
            $alreadyConnected = $user->socialIdentities()
                ->where('provider', $identity->provider)
                ->lockForUpdate()
                ->exists();

            if ($alreadyConnected) {
                throw SocialIdentityAlreadyLinked::providerAlreadyConnected($identity->provider);
            }

            /** @var SocialIdentity $link */
            $link = $user->socialIdentities()->create([
                'provider' => $identity->provider,
                'provider_user_id' => $identity->providerUserId,
                'nickname' => $identity->nickname,
                'avatar_url' => $identity->avatarUrl,
                'linked_at' => now(),
            ]);

            event(new SocialIdentityLinked(
                userId: (string) $user->getKey(),
                provider: $identity->provider,
                providerUserId: $identity->providerUserId,
            ));

            return $link;
        });
    }
}
