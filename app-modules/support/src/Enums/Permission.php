<?php

declare(strict_types=1);

namespace Konventix\Support\Enums;

/**
 * Support's capability catalogue (ADR-0013). Code, not data: which role
 * grants which capability is per-brand data managed through
 * spatie/laravel-permission, and `permissions:sync` materializes one row per
 * case here.
 *
 * Filing a report needs none of these. `OpenSupportRequest` is the module's
 * one public write and is open to guests — a report channel that required a
 * capability would be a report channel nobody can reach (ADR-0063).
 *
 * Reading a reporter's unmasked address or account is separated from seeing
 * the report itself on purpose, the same way Messaging separates "a message
 * was sent" from "this is what it said": the technical context is what
 * triage needs, the contact data is what a reply needs, and only the second
 * is personal data every time (design §11). Every unmasked view is audited
 * explicitly through `AuditRecorder` (§10.8).
 */
enum Permission: string
{
    case ViewRequests = 'support.view-requests';
    case TriageRequests = 'support.triage-requests';
    case ViewReporterContact = 'support.view-reporter-contact';
}
