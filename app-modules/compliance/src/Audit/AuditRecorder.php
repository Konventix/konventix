<?php

declare(strict_types=1);

namespace Konventix\Compliance\Audit;

use Illuminate\Support\Facades\Auth;
use Konventix\Compliance\Models\AuditLogEntry;

/**
 * The explicit half of the hybrid audit trail (decision log Round 6,
 * D-BATCH): what a module calls for an action that has no domain event to
 * subscribe to.
 *
 * Reading a participant list, streaming a CSV, downloading someone's export —
 * these are privileged and worth recording, and none of them changes state,
 * so there is nothing for the wildcard subscriber to hear. ADR-0004 pushes
 * hard towards events, and for state changes it wins; this is the residue it
 * cannot cover.
 *
 * The actor defaults to whoever is authenticated, which is right for the
 * request-time actions this exists for. A queued job passes `actorUserId`
 * explicitly or leaves it null — a job has no actor, and inventing one would
 * make the trail lie.
 *
 * Deliberately not an Action: an audit write is not a business transaction
 * and must never take part in the caller's rollback. A refused export whose
 * attempt was recorded is the correct outcome.
 */
final class AuditRecorder
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(
        string $action,
        ?string $subjectId = null,
        ?string $brandId = null,
        array $context = [],
        ?string $actorUserId = null,
    ): AuditLogEntry {
        return AuditLogEntry::create([
            'action' => $action,
            'subject_id' => $subjectId,
            'brand_id' => $brandId,
            'context' => $context,
            'actor_user_id' => $actorUserId ?? $this->currentActor(),
            'occurred_at' => now(),
        ]);
    }

    public function recordEntry(AuditEntry $entry): AuditLogEntry
    {
        return $this->record(
            action: $entry->action,
            subjectId: $entry->subjectId,
            brandId: $entry->brandId,
            context: $entry->context,
            actorUserId: $entry->actorUserId,
        );
    }

    private function currentActor(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
