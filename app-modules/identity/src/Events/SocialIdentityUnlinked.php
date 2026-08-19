<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A social provider identity was detached from an account (SPEC.md "Public
 * contract" — proposed until slice 15a).
 *
 * Recorded for the same reason as its counterpart: removing a way into an
 * account is as much a security event as adding one, and an unlink the account
 * holder did not perform is something they need to be able to see afterwards.
 */
final class SocialIdentityUnlinked implements AuditableEvent
{
    public function __construct(
        public readonly string $userId,
        public readonly string $provider,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'identity.social-identity.unlinked',
            subjectId: $this->userId,
            actorUserId: $this->userId,
            context: ['provider' => $this->provider],
        );
    }
}
