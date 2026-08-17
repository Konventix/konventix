<?php

declare(strict_types=1);

namespace Konventix\Platform\Providers;

use Illuminate\Routing\Router;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\Types\CardsBlock;
use Konventix\Platform\Blocks\Types\HeroBlock;
use Konventix\Platform\Blocks\Types\TextBlock;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\PropagatesBrandContextToQueue;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Http\Controllers\CaddyAskController;
use Konventix\Platform\Http\Controllers\MediaFileController;
use Konventix\Platform\Http\Middleware\EnsureModuleIsActive;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Realtime\BrandApplicationProvider;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Platform\Storage\MediaStorageMigration;
use Konventix\Platform\Storage\StorageMigrationRegistry;
use Laravel\Pennant\Feature;
use Laravel\Reverb\ApplicationManager;

/**
 * Kernel module. Owns brand resolution and scoping, the module gate, and the
 * navigation and UI slot registries — the machinery every other module
 * registers itself against (ADR-0003).
 *
 * This is the one provider allowed to know about cross-cutting mechanism. It
 * still knows nothing about any specific module: the gate discovers modules
 * from the registry, and modules push their own navigation and slots in.
 */
class PlatformServiceProvider extends ModuleServiceProvider
{
    /**
     * Kernel module: always active for every brand, never gated.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'platform';
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerRouteMiddleware();
        $this->registerCaddyAskRoute();
        $this->registerMediaFileRoute();
        $this->app->make(PropagatesBrandContextToQueue::class)->register();
    }

    /**
     * Registered here rather than in routes/web/ because it must escape the
     * `web` group: Caddy asks about hosts that resolve to no brand yet, so
     * ResolveBrand would 404 every ask (see CaddyAskController).
     */
    private function registerCaddyAskRoute(): void
    {
        $this->app->make(Router::class)
            ->get('/caddy/ask', CaddyAskController::class)
            ->name('platform.caddy-ask');
    }

    /**
     * Media served through the application (ADR-0043) — for disks that are
     * not `public_direct`. Registered here rather than in `routes/web/` so it
     * escapes the `web` group: the operator console renders the same URLs on
     * the admin host, where ResolveBrand would 404 them (ADR-0029), and
     * public media needs neither a session nor a brand.
     */
    private function registerMediaFileRoute(): void
    {
        $this->app->make(Router::class)
            ->get('/media/{media}/{variant?}', MediaFileController::class)
            ->where(['media' => '[0-9A-Za-z]{26}', 'variant' => '[a-z0-9_-]+'])
            ->name('platform.media.show');
    }

    public function register(): void
    {
        parent::register();

        // The reverb server resolves its applications from brand rows
        // (ADR-0025). Registered as a driver so REVERB_APP_PROVIDER selects
        // it; tests and single-app setups can keep the config driver.
        $this->callAfterResolving(
            ApplicationManager::class,
            fn (ApplicationManager $manager) => $manager->extend(
                'brands',
                fn (): BrandApplicationProvider => new BrandApplicationProvider,
            ),
        );
    }

    protected function registerBindings(): void
    {
        // Scoped, not singleton: these hold request-derived state, and under
        // Octane a singleton would leak one request's context into the next
        // request served by the same long-lived worker.
        $this->app->scoped(BrandContext::class);
        $this->app->scoped(OperatorContext::class);

        // These hold only boot-time registrations, which a long-lived worker
        // may safely share across requests.
        $this->app->singleton(ModuleGate::class);
        $this->app->singleton(NavigationRegistry::class);
        $this->app->singleton(SlotRegistry::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->singleton(StorageMigrationRegistry::class);
    }

    /**
     * Platform's own baseline block types; other modules contribute theirs
     * through the same hook (e.g. Event's `event-hero`/`countdown` via
     * `EventServiceProvider::registerBlockTypes()`).
     */
    protected function registerBlockTypes(): void
    {
        $registry = $this->app->make(BlockRegistry::class);

        $registry->register(new TextBlock);
        // Resolved through the container: HeroBlock needs MediaLibrary to
        // turn its stored media ULID into a URL (ADR-0035/ADR-0043). The
        // library is stateless, so holding one in a boot-time registry is
        // safe under Octane.
        $registry->register($this->app->make(HeroBlock::class));
        $registry->register(new CardsBlock);
    }

    /**
     * The media library's files (ADR-0043). Platform owns the copier;
     * each module describes and repoints its own records.
     */
    protected function registerStorageMigrations(): void
    {
        $this->app->make(StorageMigrationRegistry::class)->register(new MediaStorageMigration);
    }

    /**
     * Operator-console entries (ADR-0029). Modules add their own admin-area
     * items the same way; the registry filters by capability at render time.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'platform',
                label: 'Dashboard',
                url: '/admin',
                icon: 'layout-grid',
                order: 0,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Brands',
                url: '/admin/brands',
                icon: 'store',
                order: 10,
                permission: Permission::ViewBrands->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Media',
                url: '/admin/media',
                icon: 'image',
                order: 12,
                permission: Permission::ViewMedia->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Roles',
                url: '/admin/roles',
                icon: 'shield',
                order: 14,
                permission: Permission::ManageRoles->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * Define one Pennant feature per gateable module (ADR-0003).
     *
     * The default is off. A brand opts a module in explicitly rather than
     * inheriting whatever happens to be installed — "the code is deployed" and
     * "this brand offers it" are different statements.
     */
    protected function registerFeatures(): void
    {
        $gate = $this->app->make(ModuleGate::class);

        foreach ($gate->gateableModuleNames() as $module) {
            Feature::define($gate->featureName($module), fn (): bool => false);
        }
    }

    /**
     * `ensure-module` is applied automatically by ModuleServiceProvider to
     * every gateable module's routes; modules never write it themselves.
     *
     * ResolveBrand fronting the `web` group is wired in bootstrap/app.php —
     * NOT here: the HTTP kernel syncs group definitions to the router after
     * providers boot, so a prepend from this provider is silently overwritten.
     */
    private function registerRouteMiddleware(): void
    {
        $this->app->make(Router::class)
            ->aliasMiddleware('ensure-module', EnsureModuleIsActive::class);
    }
}
