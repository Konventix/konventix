<?php

declare(strict_types=1);

namespace Konventix\Platform\Modules;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InterNACHI\Modular\Support\ModuleRegistry;
use Konventix\Platform\Http\Middleware\EnsureOperatorHost;

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
        $this->registerFeatures();
        $this->registerBlockTypes();
        $this->registerSlots();
        $this->registerMessageTemplates();
        $this->registerGdprExporters();
    }

    /**
     * Whether this module can be switched off per brand.
     */
    public function isGateable(): bool
    {
        return $this->gateable;
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
     * Pennant feature definitions. A gateable module defines its own feature
     * here so the four enforcement points have something to resolve.
     */
    protected function registerFeatures(): void {}

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
     * Per-module GDPR export/anonymization handlers, registered with Compliance.
     */
    /**
     * Message templates this module registers with Messaging's
     * TemplateRegistry (same unconditional boot-time pattern as
     * registerBlockTypes/registerSlots).
     */
    protected function registerMessageTemplates(): void {}

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
