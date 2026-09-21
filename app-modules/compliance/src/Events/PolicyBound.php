<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A policy was bound to something it governs (ADR-0066, spec §8).
 *
 * Auditable, and deliberately so: binding a legal text to a product changes
 * who is bound by it without changing a word of it, which is precisely the
 * kind of change that is invisible in a diff of the text. "Who decided this
 * addendum applies to Stripe payers, and when" is a question the trail has
 * to be able to answer.
 *
 * `brandId` is null for an operator-wide binding (#76, D-CPL-05);
 * `AuditEntry` already tolerates a null brand.
 *
 * Payload is scalars only — never the PolicyBinding model (ADR-0004).
 */
final readonly class PolicyBound implements AuditableEvent
{
    public function __construct(
        public string $policyId,
        public string $bindingId,
        public ?string $brandId,
        public string $kind,
        public string $targetKey,
        public ?string $changedByUserId,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'compliance.policy.bound',
            subjectId: $this->bindingId,
            brandId: $this->brandId,
            actorUserId: $this->changedByUserId,
            context: [
                'policy_id' => $this->policyId,
                'kind' => $this->kind,
                'target_key' => $this->targetKey,
            ],
        );
    }
}
