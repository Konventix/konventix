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
 *
 * `Merged` is the other terminal state, written by `Actions\MergeUserAccounts`
 * (#81): the row survives with `users.merged_into_user_id` pointing at the
 * account that absorbed it, for the same tombstone reason `Anonymized` keeps
 * its row — but unlike anonymization, name/email/username are left alone, so
 * the address stays reserved (nobody can register it out from under the real
 * owner) and the account remains a legible audit trail rather than a
 * scrubbed one. Only the credentials are cleared, which is what makes the
 * account unreachable — see that Action's docblock for what a merge moves
 * and, at length, what it deliberately does not.
 */
enum AccountState: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Anonymized = 'anonymized';
    case Merged = 'merged';
}
