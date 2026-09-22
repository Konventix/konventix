<?php

declare(strict_types=1);

namespace Konventix\Platform\Modules;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InterNACHI\Modular\Support\ModuleRegistry;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;
use Konventix\Platform\Permissions\DefaultRole;

/**
 * Base class every module's ServiceProvider extends.
 *
 * ADR-0003: registration is unconditional and happens at boot. A module always
 * registers its routes, policies, listeners, navigation, Pennant features and
 * GDPR exporters. Whether the module is *active* is a request-time, per-brand
 * Pennant gate applied at four points — routes, navigation, UI slots, listeners.
 *
 * Subclasses override the hooks they need. Nothing here belongs in a central
 * application provider; central module wiring is forbidden.
 *
 * Note on route location: module route files live in `routes/web/`, not
 * `routes/`. InterNACHI/modular auto-`require`s every `routes/*.php` at depth 0
 * with no middleware at all, which would let a route escape both the `web`
 * group and the activation gate. One directory deeper is invisible to that
 * finder, so this provider is the only thing that can register module routes —
 * and it always applies the gate. Enforced by an architecture test.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Kernel and Core modules are always on for every brand and carry no
     * `ensure-module` middleware. Pennant-gated modules leave this true.
     */
    protected bool $gateable = true;

    /**
     * The module's Pennant feature name and route-middleware key. Kebab-case,
     * matching the directory under app-modules/.
     */
    abstract public static function moduleName(): string;

    public function register(): void
    {
        $this->registerBindings();
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerPolicies();
        $this->registerListeners();
        $this->registerNavigation();
        $this->registerHub();
        $this->registerFeatures();
        $this->registerBrandFeatures();
        $this->registerCookies();
        $this->registerBlockTypes();
        $this->registerSlots();
        $this->registerConsoleEventSources();
        $this->registerTargetProviders();
        $this->registerMessageTemplates();
        $this->registerMessageTopics();
        $this->registerSchedule();
        $this->registerGdprExporters();
        $this->registerStorageMigrations();
    }

    /**
     * Whether this module can be switched off per brand.
     */
    public function isGateable(): bool
    {
        return $this->gateable;
    }

    /**
     * Default roles this module ships (ADR-0045): starting points an operator
     * gets per brand without assembling capabilities by hand. Materialized
     * by `permissions:sync` and on module activation; a row the operator has
     * edited is never rewritten. Declared beside the module's `Permission`
     * enum and only ever over that enum's cases.
     *
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [];
    }

    /**
     * The middleware every route in this module runs through.
     *
     * @return array<int, string>
     */
    public function routeMiddleware(): array
    {
        $middleware = ['web'];

        if ($this->gateable) {
            $middleware[] = 'ensure-module:'.static::moduleName();
        }

        return $middleware;
    }

    /**
     * Container bindings owned by this module.
     */
    protected function registerBindings(): void {}

    /**
     * What this module keeps on a storage disk, so `storage:migrate` can move
     * it between disks and repoint the module's own records (ADR-0043).
     * Modules with no stored files implement nothing.
     */
    protected function registerStorageMigrations(): void {}

    /**
     * Scheduled work owned by this module. Deferred through
     * `callAfterResolving` by the implementor so the scheduler is only built
     * in the role that runs it — module wiring never goes into a central
     * kernel (ADR-0003), which is exactly what a central `schedule()` method
     * would become.
     */
    protected function registerSchedule(): void {}

    /**
     * Policies this module owns, as model class => policy class.
     *
     * @return array<class-string, class-string>
     */
    protected function policies(): array
    {
        return [];
    }

    /**
     * Load every route file in the module's `routes/web/` directory, wrapped in
     * the `web` group and — for gateable modules — the per-brand activation gate.
     *
     * `routes/admin/` files are the module's operator-console surface
     * (ADR-0029): same `web` group, but gated to the operator host and an
     * authenticated operator with the admin capability instead of a brand.
     *
     * `routes/api/` files are the stateless machine surface — see the note in
     * the body. Deliberately not gated per brand: the caller authenticates
     * itself and names its own brand, so there is no ambient one to gate on.
     */
    protected function registerRoutes(): void
    {
        $this->registerRouteDirectory('routes/web', $this->routeMiddleware());

        $this->registerRouteDirectory('routes/admin', [
            'web',
            EnsureOperatorHost::class,
            'auth',
            'can:platform.access-admin',
        ], prefix: 'admin');

        // `routes/api/` is the stateless machine surface (ADR-0033's entrance
        // API is the first): no session, no CSRF, and no brand resolved from
        // the host — the caller's own credential carries the brand. Each file
        // applies its own authentication, since there is no one scheme every
        // machine caller shares.
        $this->registerRouteDirectory('routes/api', ['api'], prefix: 'api');
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function registerRouteDirectory(string $relative, array $middleware, ?string $prefix = null): void
    {
        $directory = $this->modulePath($relative);

        if ($directory === null || ! is_dir($directory)) {
            return;
        }

        $files = glob($directory.'/*.php');

        if ($files === false || $files === []) {
            return;
        }

        sort($files);

        $registrar = Route::middleware($middleware);

        if ($prefix !== null) {
            $registrar = $registrar->prefix($prefix);
        }

        $registrar->group(function () use ($files): void {
            foreach ($files as $file) {
                require $file;
            }
        });
    }

    protected function registerPolicies(): void
    {
        foreach ($this->policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Listeners on other modules' events. Implementations must short-circuit
     * when this module's feature is off for the event's brand context.
     */
    protected function registerListeners(): void {}

    /**
     * Navigation entries contributed to the Platform registry. The registry
     * filters by active feature at render time.
     */
    protected function registerNavigation(): void {}

    /**
     * Cards and links this module contributes to the live hub (design §5.2,
     * D-HUB-05), registered with Platform's `HubRegistry` — the sibling of
     * `registerNavigation()` above, and unconditional for the same reason:
     * the registry gates by module activation and viewer capability when
     * the hub is rendered, so a module never inspects feature state to
     * decide whether to register (ADR-0003).
     *
     * A card's payload and a link's URL are closures the registry stores
     * and `HubPage` runs per request; the registry is a singleton that
     * outlives a request under Octane, so such a closure resolves its own
     * scoped bindings when it runs and captures none (`.ai/rules/providers.md`).
     */
    protected function registerHub(): void {}

    /**
     * Pennant feature definitions. A gateable module defines its own feature
     * here so the four enforcement points have something to resolve.
     */
    protected function registerFeatures(): void {}

    /**
     * Per-brand features (ADR-0047) this module offers with no module gate
     * behind them — Commerce's `commerce:shop` is the first: Commerce is
     * Core and cannot be switched off, so the shop opts in per brand on its
     * own. A module that defines such a feature (`registerFeatures()`) also
     * declares it here with Platform's `BrandFeatureRegistry`, so the brand
     * page's Features card has something to list.
     */
    protected function registerBrandFeatures(): void {}

    /**
     * Cookies this module sets on the visitor's device, declared for the
     * cookie-settings dialog. A module that sets a cookie and declares
     * nothing is lying to the person about what is on their device.
     */
    protected function registerCookies(): void {}

    /**
     * Static-page block types this module contributes to Platform's block
     * registry. Rendering gates them per brand like UI slots.
     */
    protected function registerBlockTypes(): void {}

    /**
     * UI slot contributions this module registers with Platform's
     * `SlotRegistry` (ADR-0003 enforcement point 3). Registration is
     * unconditional at boot, same as navigation and block types; the
     * registry gates rendering per module activation when a slot is read.
     *
     * Mirrors `registerBlockTypes()` — modules push their own entries in via
     * `app(SlotRegistry::class)->add(...)`, this base class knows about
     * neither slot names nor contributions.
     */
    protected function registerSlots(): void {}

    /**
     * Options for the console's event selector — see
     * `Operators\ConsoleEventSources`. Same unconditional boot-time pattern
     * as `registerSlots()`: the module that owns events contributes them,
     * because Platform owns the selector but may not read Event's tables.
     */
    protected function registerConsoleEventSources(): void {}

    /**
     * Options and labels for the console's binding-target pickers — see
     * `Targets\TargetDirectory` (ADR-0066, D-CPL-11). Same unconditional
     * boot-time pattern as `registerSlots()`: the module that owns a kind
     * contributes it, because Platform owns the registry but may read no
     * other module's tables.
     */
    protected function registerTargetProviders(): void {}

    /**
     * Per-module GDPR export/anonymization handlers, registered with Compliance.
     */
    /**
     * Message templates this module registers with Messaging's
     * TemplateRegistry (same unconditional boot-time pattern as
     * registerBlockTypes/registerSlots).
     */
    protected function registerMessageTemplates(): void {}

    /**
     * Notification topics this module declares with Messaging's
     * TopicRegistry (ADR-0041) — the unit of preference and delivery
     * policy, declared next to the templates that use it.
     */
    protected function registerMessageTopics(): void {}

    protected function registerGdprExporters(): void {}

    /**
     * Absolute path to a file inside this module, or null when the module is
     * not registered with the modular registry.
     */
    protected function modulePath(string $relative = ''): ?string
    {
        $module = $this->app->make(ModuleRegistry::class)->module(static::moduleName());

        return $module?->path($relative);
    }
}
