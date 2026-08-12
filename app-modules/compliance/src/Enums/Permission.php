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
    case ViewConsents = 'compliance.view-consents';
    case ViewSubjectRequests = 'compliance.view-subject-requests';
    case HandleSubjectRequests = 'compliance.handle-subject-requests';
    case DownloadSubjectExport = 'compliance.download-subject-export';
    case ViewAuditLog = 'compliance.view-audit-log';
    case ManageRetentionRules = 'compliance.manage-retention-rules';
}
