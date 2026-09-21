<?php

declare(strict_types=1);

namespace Konventix\Identity\Listeners;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * The other half of `Http\Middleware\EnsureRecoveryCodesGrant` (D-IDP-18,
 * round 4 Task 26): it writes the one-time permission the middleware
 * consumes.
 *
 * One listener for both legitimate reveal moments, because they are the only
 * two requests after which a set of codes is genuinely new: Fortify's
 * `ConfirmTwoFactorAuthentication` dispatches
 * `TwoFactorAuthenticationConfirmed`, and `GenerateNewRecoveryCodes` — the
 * sole dispatch site of `RecoveryCodesGenerated` — fires on the regenerate
 * POST. Enabling writes the first set inline without passing through either,
 * which is correct: the codes of an unconfirmed enrolment are not yet the
 * account's.
 *
 * Nothing request-scoped is constructor-injected (`.ai/rules/listeners.md`):
 * under Octane the dispatcher builds listeners from the worker's base
 * container, so the request is resolved inside `handle()`. Without a session
 * — a console command resetting someone's second factor, a queued job — the
 * listener does nothing at all rather than reaching for one, and no grant
 * exists for a browser to spend.
 *
 * The payload is two scalars, never a model or a Carbon: it is serialised
 * into the session store, and a stored object is a deserialisation problem
 * waiting for the next class rename.
 */
final class MintRecoveryCodesGrant
{
    private const KEY = 'two-factor.recovery-codes.reveal';

    public function handle(TwoFactorAuthenticationConfirmed|RecoveryCodesGenerated $event): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        // The vendor events annotate `$user` as a class this application
        // does not have; read it untyped and narrow to the framework contract.
        /** @var mixed $user */
        $user = $event->user;

        if (! $user instanceof Authenticatable) {
            return;
        }

        $request->session()->put(self::KEY, [
            'user' => (string) $user->getAuthIdentifier(),
            'at' => time(),
        ]);
    }
}
