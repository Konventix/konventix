<?php

declare(strict_types=1);

namespace Konventix\Identity\Enums;

/**
 * The account lifecycle state (SPEC.md "Owns" — User: "account state (pending,
 * active, locked, anonymized)"). Only `Active`/`Locked` are wired yet:
 * `pending` (email not yet verified) is already covered by
 * `email_verified_at` via Fortify's `emailVerification` feature, and
 * `anonymized` belongs to the `Gdpr/` handler in TODO.md Backlog, not this PR.
 *
 * `Locked` is enforced at login by `Actions\AuthenticateUser`
 * (`Fortify::authenticateUsing`) — a locked account fails authentication even
 * with the correct password.
 */
enum AccountState: string
{
    case Active = 'active';
    case Locked = 'locked';
}
