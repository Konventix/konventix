<?php

declare(strict_types=1);

namespace Konventix\Compliance\Audit;

/**
 * What an auditable event says about itself.
 *
 * Scalars only, like every other cross-module payload (ADR-0004): the event
 * hands over an already-flattened description rather than a model Compliance
 * would have to read.
 *
 * `action` is dot-namespaced by owning module — `registration.approved`,
 * `compliance.policy.published`. The prefix keeps the trail filterable
 * without a central catalogue of action names, which is the same reason
 * capabilities are named that way.
 */
final class AuditEntry
{
    /**
     * @param  array<string, scalar|null>  $context  whatever makes the action
     *                                               understandable a year later — never personal data beyond what
     *                                               the action is already about
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $subjectId = null,
        public readonly ?string $brandId = null,
        public readonly ?string $actorUserId = null,
        public readonly array $context = [],
    ) {}
}
