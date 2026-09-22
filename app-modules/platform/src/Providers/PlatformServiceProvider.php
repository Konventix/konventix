<?php

declare(strict_types=1);

namespace Konventix\Platform\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Konventix\Admission\Events\ParticipantAdmissionReplaced;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\Types\CardsBlock;
use Konventix\Platform\Blocks\Types\HeroBlock;
use Konventix\Platform\Blocks\Types\SocialLinksBlock;
use Konventix\Platform\Blocks\Types\TextBlock;
use Konventix\Platform\Branding\BrandIdentityResolver;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Brands\PropagatesBrandContextToQueue;
use Konventix\Platform\Cookies\CookieDeclaration;
use Konventix\Platform\Cookies\CookiePreferences;
use Konventix\Platform\Cookies\CookieRegistry;
use Konventix\Platform\Enums\CookieCategory;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Environment\DeploymentEnvironment;
use Konventix\Platform\Features\BrandFeatureRegistry;
use Konventix\Platform\Gdpr\HubAccessGdprHandler;
use Konventix\Platform\Http\Controllers\CaddyAskController;
use Konventix\Platform\Http\Controllers\MediaFileController;
use Konventix\Platform\Http\Middleware\EnsureActiveEvent;
use Konventix\Platform\Http\Middleware\EnsureFeatureIsActive;
use Konventix\Platform\Http\Middleware\EnsureModuleIsActive;
use Konventix\Platform\Http\Middleware\EnsureWaitingRoomAdmission;
use Konventix\Platform\Hub\ActiveEventContext;
use Konventix\Platform\Hub\HubCatalogue;
use Konventix\Platform\Hub\HubLayoutResolver;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Legal\NoPublicLegalTexts;
use Konventix\Platform\Legal\PublicLegalTexts;
use Konventix\Platform\Listeners\GuardNonProductionMail;
use Konventix\Platform\Listeners\ProjectHubAccess;
use Konventix\Platform\Listeners\ProjectHubLayout;
use Konventix\Platform\Metrics\PlatformMetrics;
use Konventix\Platform\Modules\ModuleGate;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Operators\ConsoleEventSources;
use Konventix\Platform\Operators\OperatorContext;
use Konventix\Platform\Pages\PageAccess;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Platform\Pricing\EndPriceCalculator;
use Konventix\Platform\Pricing\NoEndPrices;
use Konventix\Platform\Realtime\BrandApplicationProvider;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Platform\Storage\DiskResolver;
use Konventix\Platform\Storage\MediaStorageMigration;
use Konventix\Platform\Storage\StorageMigrationRegistry;
use Konventix\Platform\Targets\TargetDirectory;
use Laravel\Pennant\Feature;
use Laravel\Reverb\ApplicationManager;
use Laravel\Telescope\Telescope;

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

    /**
     * The one cross-module default (ADR-0045): everything in the catalogue,
     * for the person who runs the brand. Platform owns the catalogue, so this
     * names no other module's enum.
     *
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [DefaultRole::everything('platform.brand-admin', 'Brand Admin')];
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerRouteMiddleware();
        $this->registerCaddyAskRoute();
        $this->registerMediaFileRoute();
        $this->registerMetrics();
        $this->app->make(PropagatesBrandContextToQueue::class)->register();
    }

    /**
     * Platform's own catalogue entries (ADR-0059) — the live hub's view
     * counter (D-HUB-04). Registered the way every module registers its
     * metrics, so that the kernel module is no exception to the rule it
     * holds the others to.
     */
    private function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...PlatformMetrics::definitions());
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

        // Scoped for the same reason: it memoises which running event the
        // signed-in member is admitted to (design §5.1), an answer that
        // belongs to one request's user and brand and to no other.
        $this->app->scoped(ActiveEventContext::class);

        // These hold only boot-time registrations, which a long-lived worker
        // may safely share across requests.
        $this->app->singleton(ModuleGate::class);
        $this->app->singleton(NavigationRegistry::class);
        $this->app->singleton(HubRegistry::class);

        // Stateless readers over the registry and the cache (design §6): the
        // catalogue flattens boot-time registrations, the resolver reads the
        // cache per call — neither holds anything from a request.
        $this->app->singleton(HubCatalogue::class);
        $this->app->singleton(HubLayoutResolver::class);
        $this->app->singleton(SlotRegistry::class);
        $this->app->singleton(BlockRegistry::class);
        $this->app->singleton(ConsoleEventSources::class);
        $this->app->singleton(StorageMigrationRegistry::class);
        $this->app->singleton(CookieRegistry::class);
        $this->app->singleton(BrandFeatureRegistry::class);

        // The default answer to "where is this brand's privacy policy?" is
        // "nowhere" (#184). Compliance binds the real resolver in its own
        // provider, and `singletonIf` is what makes the order of the two
        // irrelevant: module providers register in composer's order, so a
        // plain `singleton()` here would overwrite Compliance's answer on any
        // deployment where Platform happens to register second. A contract
        // with no implementation at all would make `OperatorPresence` throw
        // rather than say "no link".
        $this->app->singletonIf(PublicLegalTexts::class, NoPublicLegalTexts::class);

        // And the default answer to "what does this offer actually cost?" is
        // "nobody here can say" (#234, D-COM-20). Commerce binds the real
        // calculator in its own provider; `singletonIf` for the reason above,
        // so provider order cannot overwrite it. Unlike
        // `Pages\RegistrantAudience`, which fails closed, this one fails soft:
        // the surface then prints the declared net amount with its
        // "excl. VAT" label rather than an end price.
        $this->app->singletonIf(EndPriceCalculator::class, NoEndPrices::class);

        // And "who can name this id for a picker?" starts out as "nobody"
        // (ADR-0066 §7.3, D-CPL-11). Each module registers a provider for the
        // kinds it owns from its own `registerTargetProviders()`; the empty
        // registry is the honest default for a build without them, and
        // `singletonIf` keeps provider order from replacing a registry
        // another provider has already filled.
        $this->app->singletonIf(TargetDirectory::class);

        // Config-derived and request-free: which kind of instance this is
        // does not change between two requests a worker serves.
        $this->app->singleton(DeploymentEnvironment::class);

        // Scoped, not singleton: DiskResolver memoizes built filesystems —
        // including a brand's own bucket (ADR-0043 point 6) — and those must
        // not survive into the next request an Octane worker serves.
        $this->app->scoped(DiskResolver::class);

        // Scoped for the same reason: it memoizes resolved media URLs, which
        // depend on the brand's storage settings and must not outlive the
        // request that resolved them.
        $this->app->scoped(BrandIdentityResolver::class);

        // Scoped: it memoizes "is this viewer a registrant of this brand" so
        // a menu full of registrant pages asks Registration once per
        // request, and not once per worker lifetime. Platform binds no
        // `Pages\RegistrantAudience` itself — Registration does, against
        // its own table — and `PageAccess` resolves it lazily, failing
        // closed when nothing is bound.
        $this->app->scoped(PageAccess::class);
    }

    /**
     * Every mail the application sends passes the non-production guard
     * (GitLab #68, ADR-0053) — Fortify's notifications and Messaging's
     * channel alike, which is why it hangs on the mailer's own event rather
     * than on any one sender. Registered here because Platform owns the
     * notion of "which kind of instance is this".
     */
    protected function registerListeners(): void
    {
        Event::listen(MessageSending::class, GuardNonProductionMail::class);

        // Admission's participant events feed the `hub_access` projection
        // (design §5.1, ADR-0004): Platform learns who is admitted to what
        // from these alone and never reads Admission's presence table.
        Event::listen(ParticipantAdmitted::class, [ProjectHubAccess::class, 'handleAdmitted']);
        Event::listen(ParticipantPresenceChanged::class, [ProjectHubAccess::class, 'handlePresenceChanged']);
        // The row mirrors `admission_id` and Resources' hub link is built
        // out of it, so a survivor replacing a revoked admission has to
        // reach the mirror too (D-HUB-01's survivor rule).
        Event::listen(ParticipantAdmissionReplaced::class, [ProjectHubAccess::class, 'handleAdmissionReplaced']);
        Event::listen(ParticipantHubAccessEnded::class, [ProjectHubAccess::class, 'handleEnded']);

        // Event's layout event feeds the `hub_layouts` projection the same
        // way (design §6): Platform holds a copy of the operator's
        // composition and never reads Event's table.
        Event::listen(EventHubLayoutChanged::class, ProjectHubLayout::class);
    }

    /**
     * The `hub_access` projection is the one thing Platform holds about a
     * person rather than about a brand (design §9); Compliance exports and
     * erases it through this handler like every other module's.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new HubAccessGdprHandler);
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
        $registry->register(new HeroBlock);
        $registry->register(new CardsBlock);
        $registry->register(new SocialLinksBlock);
    }

    /**
     * The cookies Platform itself sets. `config('session.cookie')` is a
     * brand-prefixed pattern resolved at request time (`Str::slug(APP_NAME)
     * .'-session'` at boot) — declared here as the boot-time value, and the
     * purpose text says the actual name carries the brand.
     */
    protected function registerCookies(): void
    {
        $this->app->make(CookieRegistry::class)->register(
            new CookieDeclaration(config('session.cookie'), 'Keeps you signed in and holds what you are doing on this site between two clicks.', CookieCategory::Necessary, 'session', 'platform'),
            new CookieDeclaration('XSRF-TOKEN', 'Protects forms against being submitted by another site on your behalf.', CookieCategory::Necessary, 'session', 'platform'),
            new CookieDeclaration(CookiePreferences::COOKIE, 'Remembers the choice you make in this dialog.', CookieCategory::Necessary, '1 year', 'platform'),
            new CookieDeclaration('appearance', 'Remembers whether you chose the light or dark appearance.', CookieCategory::Functional, '1 year', 'platform'),
            new CookieDeclaration('sidebar_state', 'Remembers whether you collapsed the console sidebar.', CookieCategory::Functional, '7 days', 'platform'),
        );
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
                label: 'Pages',
                url: '/admin/pages',
                icon: 'file-text',
                order: 11,
                permission: Permission::ViewStaticPages->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Menu',
                url: '/admin/menu',
                icon: 'menu',
                order: 11,
                permission: Permission::ManageBrandMenu->value,
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
                label: 'Storage',
                url: '/admin/storage-settings',
                icon: 'hard-drive',
                order: 13,
                permission: Permission::ManageStorageSettings->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Operator',
                url: '/admin/settings/operator',
                icon: 'building',
                order: 15,
                permission: Permission::ManageOperator->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Footer',
                url: '/admin/settings/footer',
                icon: 'scroll-text',
                order: 16,
                permission: Permission::ManageFooterLinks->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            // The brand shell's own navigation. Registered here so the
            // topbar has a source rather than a hardcoded array; other
            // modules add their brand-facing entries the same way.
            new NavigationItem(
                module: 'platform',
                label: 'Home',
                url: '/',
                icon: 'house',
                order: 0,
                area: NavigationItem::AREA_BRAND,
            ),
            // The packages' own dashboards and the health page are Blade, not
            // Inertia: `external` makes the shell render a plain anchor, the
            // same way `DiagnosticsCard` already does. Their paths are config
            // (the chart may move them), so they are read from it here too.
            new NavigationItem(
                module: 'platform',
                label: 'Diagnostics',
                url: '/'.config('pulse.path'),
                icon: 'activity',
                order: 90,
                permission: Permission::ViewDiagnostics->value,
                area: NavigationItem::AREA_ADMIN,
                external: true,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Queues',
                url: '/'.config('horizon.path'),
                icon: 'list-checks',
                order: 91,
                permission: Permission::ViewDiagnostics->value,
                area: NavigationItem::AREA_ADMIN,
                external: true,
            ),
            new NavigationItem(
                module: 'platform',
                label: 'Health',
                url: '/admin/health',
                icon: 'heart-pulse',
                order: 92,
                permission: Permission::ViewDiagnostics->value,
                area: NavigationItem::AREA_ADMIN,
                external: true,
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
     * Platform's own card on the operator dashboard's `admin.dashboard` slot
     * (ADR-0003 enforcement point 3) — the console composes its landing page
     * the same way the per-event dashboard does.
     *
     * `static` because SlotRegistry is a singleton that outlives the request
     * under Octane, and a closure capturing `$this` would pin this provider
     * in memory with it.
     */
    protected function registerSlots(): void
    {
        $this->app->make(SlotRegistry::class)->add(
            slot: 'admin.dashboard',
            module: 'platform',
            component: 'platform/DiagnosticsCard',
            props: static fn (array $context): array => [
                'links' => self::diagnosticsLinks($context['user'] ?? null),
            ],
            order: 90,
        );
    }

    /**
     * The packages' own dashboards, each behind the capability it needs.
     * Telescope is held apart on purpose (Permission::ViewRequestLog): it
     * records request bodies, so linking it is the power to read other
     * people's personal data — and it is a dev dependency, so it is only
     * offered where it is installed and outside production.
     *
     * @return list<array{label: string, url: string, description: string}>
     */
    private static function diagnosticsLinks(?Authenticatable $user): array
    {
        if ($user === null) {
            return [];
        }

        $gate = Gate::forUser($user);
        $links = [];

        if ($gate->allows(Permission::ViewDiagnostics->value)) {
            $links[] = [
                'label' => 'Pulse',
                'url' => '/'.config('pulse.path'),
                'description' => 'How the application is behaving right now',
            ];
            $links[] = [
                'label' => 'Horizon',
                'url' => '/'.config('horizon.path'),
                'description' => 'The queues, and what is stuck in them',
            ];
            $links[] = [
                'label' => 'Health',
                'url' => route('admin.health', absolute: false),
                'description' => 'Database, Redis, cache, scheduler and disk checks',
            ];
        }

        if ($gate->allows(Permission::ViewRequestLog->value)
            && class_exists(Telescope::class)
            && ! app()->isProduction()) {
            $links[] = [
                'label' => 'Telescope',
                'url' => '/'.config('telescope.path'),
                'description' => 'Request log — records request bodies, so it is a separate capability',
            ];
        }

        return $links;
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
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('ensure-module', EnsureModuleIsActive::class);
        $router->aliasMiddleware('ensure-feature', EnsureFeatureIsActive::class);

        // ADR-0047 point 5: the waiting room's own enforcement point,
        // beside the two above. Unlike `ensure-feature`, this alias names
        // no feature — Commerce's bound `WaitingRoomGate` decides that
        // itself, so a module applying this alias (Registration's public
        // form) never names Commerce's feature string either.
        $router->aliasMiddleware('ensure-waiting-room', EnsureWaitingRoomAdmission::class);

        // The live hub's gate (design §7.1): `/live` is only for a member
        // admitted to a running event on this brand. Named as an alias so
        // the route reads what it requires, like the three above.
        $router->aliasMiddleware('ensure-active-event', EnsureActiveEvent::class);
    }
}
