<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use RuntimeException;

/**
 * The provider identity presented cannot be attached to this account.
 *
 * Its own exception rather than a generic failure because both refusals are
 * a different situation for the person in front of the screen from a failed
 * handshake: their Steam account works fine, it simply cannot be linked
 * *here*. The message says which of the two it is, because both have a
 * self-service way out that a vague message would hide.
 *
 * `forUsername()` names the account that holds the identity. That is
 * deliberate and not a disclosure: the person is signed into that very
 * Steam account in this browser right now (the driver verified the
 * assertion before this Action was ever called), so they already control
 * the one login the other account has — telling them which username it
 * belongs to hands them nothing they could not learn by signing out and in
 * with Steam. What must never be named is the holder's display name or the
 * Steam persona: the `username` is the profile-of-record handle
 * (ADR-0051), the one thing about an account that is public by design.
 * Self-service merging was rejected for #81 (operator-only), so "sign in
 * there and disconnect it" is the honest instruction.
 */
final class SocialIdentityAlreadyLinked extends RuntimeException
{
    /**
     * The identity belongs to a different account, whose handle is named.
     */
    public static function forUsername(string $provider, string $username): self
    {
        $label = ucfirst($provider);

        return new self(
            "That {$label} account is already linked to the account “{$username}”. "
            .'Sign in there to disconnect it first, or contact support if that account is not yours.'
        );
    }

    /**
     * This account already holds an identity at that provider, and one
     * account holds at most one per provider (the `(user_id, provider)`
     * unique index). Without this refusal the insert dies on the index
     * with a `QueryException` — a 500 for the person, not an answer.
     */
    public static function providerAlreadyConnected(string $provider): self
    {
        $label = ucfirst($provider);

        return new self(
            "A different {$label} account is already connected to your account. "
            ."Disconnect the connected {$label} account first, then connect this one."
        );
    }
}
