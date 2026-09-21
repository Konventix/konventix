<?php

declare(strict_types=1);

namespace Konventix\Compliance\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * A binding was taken off a policy — the text no longer applies there (ADR-0066, spec §8).
 *
 * Auditable, and deliberately so: binding a legal text to a product changes
 * who is bound by it without changing a word of it, and un-binding it stops a
 * text being asked for at all. Removing the last binding of a policy widens
 * it back to the whole brand rather than narrowing it (D-CPL-04), which is
 * the least obvious consequence in this whole feature and exactly why it is
 * recorded.
 *
 * `brandId` is null for an operator-wide binding (#76, D-CPL-05);
 * `AuditEntry` already tolerates a null brand.
 *
 * Payload is scalars only — never the PolicyBinding model (ADR-0004).
 */
final readonly class PolicyBindingRemoved implements AuditableEvent
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
            action: 'compliance.policy.binding-removed',
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
