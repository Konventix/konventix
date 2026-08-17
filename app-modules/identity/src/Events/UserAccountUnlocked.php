<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * Contract event (SPEC.md): a lock on the account is lifted.
 * Scalars only (ADR-0004).
 */
final class UserAccountUnlocked
{
    public function __construct(
        public readonly string $userId,
        public readonly string $unlockedAt,
    ) {}
}
