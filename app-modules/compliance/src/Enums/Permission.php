<?php

declare(strict_types=1);

namespace Konventix\Compliance\Enums;

/**
 * The capability catalog Compliance owns (ADR-0013). This enum is the only
 * place the catalog is defined in code; roles and their grants are per-brand
 * data managed through spatie/laravel-permission. Add a case here and re-sync
 * `permissions:sync` — never create permission rows by hand.
 *
 * Downloading a subject export is deliberately separate from handling
 * requests: seeing that a request exists and seeing its contents are
 * different levels of access (SPEC "Capabilities").
 */
enum Permission: string
{
    case ViewPolicies = 'compliance.view-policies';
    case ManagePolicies = 'compliance.manage-policies';
    case PublishPolicy = 'compliance.publish-policy';

    /**
     * D-CPL-16 / ADR-0066: deciding *where* a text applies — binding it to an
     * event, a registration type, a settlement provider or an entry
     * condition. Separate from `ManagePolicies` on purpose: binding a text to
     * a product changes who is bound by it without changing a word of it,
     * and the person who edits wording is not necessarily the person who
     * decides which sales it governs.
     *
     * Compliance ships no default role of its own, so this reaches an
     * operator through Platform's `platform.brand-admin`, which
     * `DefaultRole::everything()` expands against the whole catalogue
     * (ADR-0045). Run `permissions:sync` after adding a case — never create
     * permission rows by hand.
     */
    case ManagePolicyBindings = 'compliance.manage-policy-bindings';
    case ViewConsents = 'compliance.view-consents';
    case ViewSubjectRequests = 'compliance.view-subject-requests';
    case HandleSubjectRequests = 'compliance.handle-subject-requests';
    case DownloadSubjectExport = 'compliance.download-subject-export';
    case ViewAuditLog = 'compliance.view-audit-log';
    case ManageRetentionRules = 'compliance.manage-retention-rules';
}
