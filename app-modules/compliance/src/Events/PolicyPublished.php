<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * Emitted when a policy version becomes effective (SPEC "Public contract").
 * Payload is scalars only — never the PolicyVersion model (ADR-0004).
 *
 * `brandId` is null for an operator-wide text (GitLab #76): the operator is
 * one legal entity behind all its brands (ADR-0016), and a text every brand
 * inherits belongs to none of them. `AuditEntry` already tolerates a null
 * brand — half the trail is written on the operator host where no brand
 * exists — so the entry stays honest rather than naming an arbitrary one.
 *
 * Auditable: publishing binds everyone who registers afterwards, and a
 * required version invalidates every existing agreement. "Who made this text
 * binding, and when" is exactly the kind of question the trail exists for.
 */
final class PolicyPublished implements AuditableEvent
{
    public function __construct(
        public readonly string $policyId,
        public readonly string $versionId,
        public readonly ?string $brandId,
        public readonly string $slug,
        public readonly string $effectiveAt,
        public readonly bool $requiresConsent,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'compliance.policy.published',
            subjectId: $this->versionId,
            brandId: $this->brandId,
            context: [
                'policy_id' => $this->policyId,
                'slug' => $this->slug,
                'effective_at' => $this->effectiveAt,
                'requires_consent' => $this->requiresConsent,
            ],
        );
    }
}
