<?php

declare(strict_types=1);

namespace Konventix\Compliance\Gdpr;

/**
 * Resolves an address to the account behind it, without Compliance ever
 * importing Identity's `User` model (ADR-0004). Identity is the only module
 * that knows what an account is; Compliance only needs to know whether one
 * exists for a given address at the moment a verification link is followed
 * (ADR-0050 §3).
 *
 * Bound to `IdentitySubjectResolver` from `IdentityServiceProvider`.
 */
interface SubjectResolver
{
    /** The account's ULID, or null if no account carries this address. */
    public function resolveUserIdByEmail(string $email): ?string;
}
