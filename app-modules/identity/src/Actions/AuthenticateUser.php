<?php

declare(strict_types=1);

namespace Konventix\Identity\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Konventix\Identity\Enums\AccountState;
use Konventix\Identity\Models\User;
use Laravel\Fortify\Fortify;

/**
 * Wired via `Fortify::authenticateUsing()` in `IdentityServiceProvider`. This
 * callback replaces Fortify's default credential lookup entirely (both
 * `AttemptToAuthenticate` and, when two-factor authentication is enabled,
 * `RedirectIfTwoFactorAuthenticatable::validateCredentials()` call it), so it
 * must do the full job: find the user, check the password, and — the reason
 * this class exists — refuse a locked account (SPEC.md "Account lifecycle:
 * locking, unlocking...").
 *
 * Returning `null` is read by Fortify as "credentials did not match" (a
 * generic, rate-limited failure — `auth.failed`). A locked account did
 * present the correct password, so it gets its own message instead of being
 * folded into that generic failure; this does not weaken the "don't reveal
 * whether an email exists" posture ADR-0016 asks for elsewhere; the caller
 * already proved they hold the password.
 */
final class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $user = User::query()
            ->where(Fortify::username(), $request->input(Fortify::username()))
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        // A social-only account has no password at all (the column is
        // nullable since slice 15a). It must fail the way a wrong password
        // fails — generically — and it must never reach `Hash::check`, which
        // would be handed a null hash.
        if (! $user->hasPassword()) {
            return null;
        }

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            return null;
        }

        if ($user->state === AccountState::Locked) {
            throw ValidationException::withMessages([
                Fortify::username() => [__('This account has been locked. Contact support to regain access.')],
            ]);
        }

        // An anonymized account has no owner left to log in: the credentials
        // were scrambled by the GDPR handler, so this is belt and braces —
        // but the row survives as a tombstone (Round 6 D-BATCH), and a
        // tombstone must never authenticate.
        if ($user->state === AccountState::Anonymized) {
            return null;
        }

        return $user;
    }
}
