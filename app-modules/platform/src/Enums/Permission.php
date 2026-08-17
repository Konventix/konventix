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
    case ManageStaticPages = 'platform.manage-static-pages';
    case ManageRoles = 'platform.manage-roles';
    case ViewCrossBrand = 'platform.view-cross-brand';
    case ViewMedia = 'platform.view-media';
    case ManageMedia = 'platform.manage-media';
    case ManageStorageSettings = 'platform.manage-storage-settings';
}
