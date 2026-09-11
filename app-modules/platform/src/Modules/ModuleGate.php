<?php

declare(strict_types=1);

namespace Konventix\Platform\Modules;

use Illuminate\Support\Collection;
use InterNACHI\Modular\Support\ModuleRegistry;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Laravel\Pennant\Feature;

/**
 * The single answer to "is module X active for the current brand?" (ADR-0003).
 *
 * All four enforcement points route through here — routes via
 * EnsureModuleIsActive, navigation via NavigationRegistry, UI slots via
 * SlotRegistry, and listeners by calling `isActive()` before doing work — so
 * activation semantics live in exactly one place.
 *
 * Holds only boot-time registrations, so a long-lived Octane worker may share
 * this singleton across requests; `BrandContext` is scoped, not singleton, so
 * it is resolved at call time here rather than constructor-injected — the
 * same pattern `BlockRegistry::resolveActive()` uses.
 */
class ModuleGate
{
    /** @var array<string, bool>|null */
    private ?array $gateable = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * Kernel and Core modules are always active. Gateable modules resolve
     * through Pennant, scoped to the current brand.
     *
     * With no brand in context (console, queue without brand) a gateable module
     * reads as inactive: refusing to act is the safe default when we cannot
     * tell which brand we are working for.
     */
    public function isActive(string $module): bool
    {
        if (! $this->isGateable($module)) {
            return true;
        }

        $brand = app(BrandContext::class)->current();

        if ($brand === null) {
            return false;
        }

        return Feature::for($brand)->active($this->featureName($module));
    }

    /**
     * The same question for an explicit brand, for code paths that run with
     * no ambient brand at all — a payment webhook, the scheduler, a console
     * request on the operator host — but whose event payload names one.
     * `isActive()` reads "no brand" as "inactive", which is right for a
     * request and wrong for a `RegistrationConfirmed` that Stripe's webhook
     * fired: the brand is known, it just is not in context.
     *
     * `Brand` itself carries no global scope — it is the scoping level, not a
     * scoped model — so the lookup needs no escape hatch. What matters is
     * that the ambient context is never touched.
     */
    public function isActiveForBrand(string $module, string $brandId): bool
    {
        if (! $this->isGateable($module)) {
            return true;
        }

        $brand = Brand::query()->find($brandId);

        if ($brand === null) {
            return false;
        }

        return Feature::for($brand)->active($this->featureName($module));
    }

    /**
     * Whether a module can be switched off at all, read from its
     * ServiceProvider rather than from a central list.
     */
    public function isGateable(string $module): bool
    {
        return $this->gateableModules()[$module] ?? false;
    }

    /**
     * Every gateable module's name.
     *
     * @return array<int, string>
     */
    public function gateableModuleNames(): array
    {
        return array_keys(array_filter($this->gateableModules()));
    }

    /**
     * Every module's name, gateable or not — the console's brand page lists
     * both so activation tells the whole story.
     *
     * @return array<int, string>
     */
    public function allModuleNames(): array
    {
        return array_keys($this->gateableModules());
    }

    /**
     * The Pennant feature name for a module.
     */
    public function featureName(string $module): string
    {
        return 'module:'.$module;
    }

    /**
     * Build the module => gateable map by asking each module's ServiceProvider.
     * Nothing here enumerates modules by hand; adding a module cannot mean
     * remembering to update a central list.
     *
     * @return array<string, bool>
     */
    private function gateableModules(): array
    {
        if ($this->gateable !== null) {
            return $this->gateable;
        }

        return $this->gateable = Collection::make($this->registry->modules())
            ->mapWithKeys(function ($module): array {
                $provider = $this->providerFor($module->name, $module->namespace());

                if ($provider === null || ! is_subclass_of($provider, ModuleServiceProvider::class)) {
                    return [];
                }

                return [$module->name => (new $provider(app()))->isGateable()];
            })
            ->all();
    }

    /**
     * The provider class a module declares, if it exists. Callers verify it is
     * actually a ModuleServiceProvider — a module could ship a plain provider.
     *
     * @return class-string|null
     */
    private function providerFor(string $module, string $namespace): ?string
    {
        $class = $namespace.'Providers\\'.str_replace('-', '', ucwords($module, '-')).'ServiceProvider';

        return class_exists($class) ? $class : null;
    }
}
