<?php

declare(strict_types=1);

namespace Konventix\Identity\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A social provider identity was attached to an account (SPEC.md "Public
 * contract" — listed as proposed until slice 15a shipped it).
 *
 * Payload is scalars only (ADR-0004). The provider's own id is deliberately
 * *not* in the audit context: the trail should record that a Steam account was
 * linked, not which one — the identifier itself lives in the table the GDPR
 * handler already exports and erases.
 *
 * Auditable because adding a way into an account is exactly the kind of
 * privileged change the trail exists for. An attacker who links their own
 * provider identity to somebody else's account has a permanent second key;
 * this is the row that shows when that happened.
 */
final class SocialIdentityLinked implements AuditableEvent
{
    public function __construct(
        public readonly string $userId,
        public readonly string $provider,
        public readonly string $providerUserId,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'identity.social-identity.linked',
            subjectId: $this->userId,
            actorUserId: $this->userId,
            context: ['provider' => $this->provider],
        );
    }
}
