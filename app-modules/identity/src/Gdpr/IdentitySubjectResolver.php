<?php

declare(strict_types=1);

namespace Konventix\Identity\Gdpr;

use Konventix\Compliance\Gdpr\SubjectResolver;
use Konventix\Identity\Models\User;

/**
 * Identity's answer to Compliance's `SubjectResolver` contract (ADR-0004):
 * Compliance never imports `User` directly, so this is the one place that
 * translates an address into the account behind it.
 */
final class IdentitySubjectResolver implements SubjectResolver
{
    public function resolveUserIdByEmail(string $email): ?string
    {
        /** @var string|null */
        return User::query()->where('email', mb_strtolower(trim($email)))->value('id');
    }
}
