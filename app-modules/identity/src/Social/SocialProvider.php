<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One way into an account that is not an email and a password.
 *
 * SPEC.md: "Social login behind a `SocialProvider` driver contract. Steam is a
 * driver, so are Discord, Google, and whatever comes next." Steam is the only
 * driver at release (decision log D-IDN-01, as amended), and this contract is
 * what stops that from meaning "Steam is hardcoded" — the shape below is
 * deliberately the *hardest* case rather than the average one, so an OAuth2
 * provider slots in without widening it:
 *
 * - `identityFromCallback()` returns an identity, not a user. Deciding whether
 *   that identity logs somebody in, links to the current account, or starts a
 *   signup is the caller's job, and is identical for every provider.
 * - The returned email is nullable, because Steam asserts none. A driver that
 *   does supply one fills the field; nothing downstream may assume it is there.
 *
 * Implementations must treat a failed handshake as `SocialAuthenticationFailed`
 * rather than returning a half-populated identity: there is no such thing as a
 * partially authenticated login.
 */
interface SocialProvider
{
    /**
     * The key this driver is addressed by in routes and stored on
     * `social_identities.provider`. Stable — it is persisted data.
     */
    public function key(): string;

    /**
     * The provider's name as a person would recognise it, for buttons and the
     * linked-accounts list.
     */
    public function label(): string;

    /**
     * Send the person to the provider to prove who they are.
     */
    public function redirect(): RedirectResponse;

    /**
     * The verified identity behind an inbound callback.
     *
     * @throws SocialAuthenticationFailed when the callback cannot be trusted —
     *                                    a failed assertion, a replayed or
     *                                    missing nonce, an unparseable id.
     */
    public function identityFromCallback(Request $request): SocialIdentityData;
}
