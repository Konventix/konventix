<?php

declare(strict_types=1);

namespace Konventix\Registration\Events;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditEntry;

/**
 * An operator defined a new entry condition (ADR-0066, spec §8).
 *
 * Auditable: a condition is what a reduced fare rests on and what the door
 * will ask a person for. "Who invented this requirement, and when" is
 * exactly the kind of question the trail exists for. `Audit\AuditableEvent`
 * is one of Compliance's sanctioned contract surfaces
 * (`tests/Unit/ArchitectureTest.php`); its models stay forbidden.
 *
 * Scalars only (ADR-0004). `key` and `verification` are bounded enough to
 * appear in a log line; `assertionText` deliberately is not carried — the
 * trail records that the condition changed, not a copy of its wording.
 */
final readonly class EntryConditionCreated implements AuditableEvent
{
    public function __construct(
        public string $entryConditionId,
        public string $brandId,
        public string $key,
        public string $name,
        public string $verification,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'registration.entry-condition.created',
            subjectId: $this->entryConditionId,
            brandId: $this->brandId,
            context: [
                'key' => $this->key,
                'name' => $this->name,
                'verification' => $this->verification,
            ],
        );
    }
}
