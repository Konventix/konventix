<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * Account contact/profile data changed (SPEC.md "Public contract").
 * Consumers maintaining read models (Messaging recipients, future
 * registrant/holder models) re-project from this payload — idempotently,
 * since a replay carries the same current values. Scalars only (ADR-0004).
 */
final readonly class UserProfileUpdated
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $displayName,
        public string $locale,
        public string $updatedAt,
    ) {}
}
