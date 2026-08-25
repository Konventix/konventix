<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Slots\SlotRegistry;

/**
 * Operator console entry point (ADR-0029). Cross-brand counts use the
 * explicit withoutBrandScope() escape hatch — the hardened BrandScope returns
 * nothing on this host otherwise, by design.
 *
 * Beyond its own counts the page is composed from the `admin.dashboard` UI
 * slot (ADR-0003 enforcement point 3), the same way the per-event dashboard
 * is: contributing modules decide what they show and compute their own props
 * from the viewing user, and this controller never learns what they are.
 */
class AdminDashboardController
{
    public function __construct(private readonly SlotRegistry $slots) {}

    public function __invoke(Request $request, OperatorContext $context): Response
    {
        return Inertia::render('admin/Dashboard', [
            'operator' => [
                'name' => $context->current()?->name,
            ],
            'counts' => [
                'brands' => Brand::query()->count(),
                'pages' => StaticPage::withoutBrandScope()->count(),
            ],
            'slots' => $this->slots->for('admin.dashboard', ['user' => $request->user()])
                ->map(fn (array $entry): array => [
                    'module' => $entry['module'],
                    'component' => $entry['component'],
                    'props' => $entry['props'],
                ])
                ->all(),
        ]);
    }
}
