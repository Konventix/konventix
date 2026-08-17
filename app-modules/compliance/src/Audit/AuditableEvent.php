<?php

declare(strict_types=1);

namespace Konventix\Compliance\Audit;

/**
 * The marker half of the hybrid audit trail (decision log Round 6, D-BATCH).
 *
 * An event that describes a privileged action implements this, and
 * Compliance's wildcard subscriber records it. The module never calls into
 * Compliance to log anything — it declares that its own event is worth
 * recording and keeps owning what the record says (ADR-0004).
 *
 * This is a contract surface, like `Gdpr\GdprHandler`: importing it across a
 * module boundary is sanctioned, importing Compliance's models is not.
 *
 * The other half is `AuditRecorder`, for actions that have no domain event to
 * subscribe to — viewing a participant list, exporting a CSV. Between them
 * they cover everything; a single mechanism could not.
 */
interface AuditableEvent
{
    public function auditEntry(): AuditEntry;
}
