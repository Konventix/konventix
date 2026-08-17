<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * Contract event (SPEC.md): the account is locked, manually or by policy.
 * Scalars only (ADR-0004).
 */
final class UserAccountLocked
{
    public function __construct(
        public readonly string $userId,
        public readonly string $reasonCode,
        public readonly string $lockedAt,
    ) {}
}
