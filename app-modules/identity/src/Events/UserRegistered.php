<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

/**
 * A new account exists (SPEC.md "Public contract"). `brandId` names the
 * brand the account was created *through*, not an owner — accounts are
 * operator-global (ADR-0016). Scalars only (ADR-0004); Messaging's
 * recipient read model is the first consumer.
 */
final readonly class UserRegistered
{
    public function __construct(
        public string $userId,
        public string $brandId,
        public string $email,
        public string $displayName,
        public string $locale,
        public string $registeredAt,
    ) {}
}
