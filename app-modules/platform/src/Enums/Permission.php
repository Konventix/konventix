<?php

declare(strict_types=1);

namespace Konventix\Platform\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Platform's capability catalog (ADR-0013). Cases are code; role rows granting
 * them are data. Never hand-write permission rows — edit this enum and run
 * `permissions:sync`.
 */
#[TypeScript]
enum Permission: string
{
    case AccessAdmin = 'platform.access-admin';
    case ViewBrands = 'platform.view-brands';
    case ManageBrands = 'platform.manage-brands';
    case ManageBrandDomains = 'platform.manage-brand-domains';
    case ManageBrandTheme = 'platform.manage-brand-theme';
    case ManageModuleActivation = 'platform.manage-module-activation';
    case ViewStaticPages = 'platform.view-static-pages';
    case ManageStaticPages = 'platform.manage-static-pages';
    case ManageRoles = 'platform.manage-roles';
    case ViewCrossBrand = 'platform.view-cross-brand';
    case ViewMedia = 'platform.view-media';
    case ManageMedia = 'platform.manage-media';
    case ManageStorageSettings = 'platform.manage-storage-settings';
    case ManageOperator = 'platform.manage-operator';
    case ManageFooterLinks = 'platform.manage-footer-links';

    /**
     * Pulse and Horizon: how the system is behaving, and the queues. Read-only
     * metrics plus Horizon's retry/delete controls.
     */
    case ViewDiagnostics = 'platform.view-diagnostics';

    /**
     * Telescope, held apart from the rest deliberately. It records request
     * bodies, so holding this is the power to read other people's personal
     * data — registrations, subject requests, invoice recipients — not just to
     * see that the system is slow.
     */
    case ViewRequestLog = 'platform.view-request-log';
}
