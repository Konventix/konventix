<?php

declare(strict_types=1);

namespace Konventix\Compliance\Listeners;

use Konventix\Compliance\Audit\AuditableEvent;
use Konventix\Compliance\Audit\AuditRecorder;

/**
 * The subscriber half of the hybrid audit trail (decision log Round 6,
 * D-BATCH): one wildcard listener over every dispatched event, recording the
 * ones that declare themselves auditable.
 *
 * A wildcard rather than a list of event classes, because a list is a second
 * place to keep in sync and the module that adds an event is not the module
 * that would remember to edit Compliance. The cost is one `instanceof` per
 * dispatched event, which is what a marker interface makes cheap.
 *
 * Eloquent's own `eloquent.*` events and framework internals flow through
 * here too and are ignored by the same check.
 *
 * Registered explicitly in ComplianceServiceProvider — modular event
 * discovery is off and must stay off (.ai/rules/listeners.md).
 */
final class RecordAuditableEvents
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof AuditableEvent) {
            return;
        }

        $this->recorder->recordEntry($event->auditEntry());
    }
}
