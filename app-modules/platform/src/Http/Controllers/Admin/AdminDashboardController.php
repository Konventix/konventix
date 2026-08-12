<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Operators\OperatorContext;

/**
 * Operator console entry point (ADR-0029). Cross-brand counts use the
 * explicit withoutBrandScope() escape hatch — the hardened BrandScope returns
 * nothing on this host otherwise, by design.
 */
class AdminDashboardController
{
    public function __invoke(OperatorContext $context): Response
    {
        return Inertia::render('admin/Dashboard', [
            'operator' => [
                'name' => $context->current()?->name,
            ],
            'counts' => [
                'brands' => Brand::query()->count(),
                'pages' => StaticPage::withoutBrandScope()->count(),
            ],
        ]);
    }
}
