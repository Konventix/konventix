<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\AdminFavorite;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Operators\OperatorContext;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // The current brand's Reverb app key (ADR-0025). Echo is
            // configured from this, because the key differs per brand and
            // therefore cannot be a build-time VITE constant.
            'reverb' => [
                'key' => app(BrandContext::class)->current()?->reverb_app_key,
            ],
            // Operator console shell data (ADR-0029): only present on the
            // admin host, capability-filtered by the registry.
            'admin' => app(OperatorContext::class)->hasOperator() ? [
                'operatorName' => app(OperatorContext::class)->current()?->name,
                'navigation' => app(NavigationRegistry::class)
                    ->visible(NavigationItem::AREA_ADMIN)
                    ->map(fn (NavigationItem $item): array => $item->toArray())
                    ->all(),
                // In the user's own drag order (ADR-0042), not alphabetical.
                'favorites' => $request->user()
                    ? AdminFavorite::query()
                        ->where('user_id', $request->user()->getKey())
                        ->inUserOrder()
                        ->get(['id', 'label', 'url', 'position'])
                        ->map(fn (AdminFavorite $favorite): array => [
                            'id' => $favorite->getKey(),
                            'label' => $favorite->label,
                            'url' => $favorite->url,
                        ])
                        ->all()
                    : [],
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
