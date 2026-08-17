<?php

declare(strict_types=1);

namespace Konventix\Identity\Enums;

/**
 * The account lifecycle state (SPEC.md "Owns" — User: "account state (pending,
 * active, locked, anonymized)"). `pending` stays absent on purpose: email
 * verification is already carried by `email_verified_at` via Fortify's
 * `emailVerification` feature, and a second representation of the same fact
 * would be one to keep in sync.
 *
 * `Locked` is enforced at login by `Actions\AuthenticateUser`
 * (`Fortify::authenticateUsing`) — a locked account fails authentication even
 * with the correct password. `Anonymized` is a terminal state written by
 * `Gdpr\IdentityGdprHandler`: the row survives so foreign ULID references
 * stay resolvable (Round 6 D-BATCH), and it fails authentication for the same
 * reason `Locked` does — an anonymized account has no owner left to log in.
 */
enum AccountState: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Anonymized = 'anonymized';
}
