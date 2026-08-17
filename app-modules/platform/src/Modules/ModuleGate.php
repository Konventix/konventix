<?php

declare(strict_types=1);

namespace Konventix\Platform\Modules;

use Illuminate\Support\Collection;
use InterNACHI\Modular\Support\ModuleRegistry;
use Konventix\Platform\Brands\BrandContext;
use Laravel\Pennant\Feature;

/**
 * The single answer to "is module X active for the current brand?" (ADR-0003).
 *
 * All four enforcement points route through here — routes via
 * EnsureModuleIsActive, navigation via NavigationRegistry, UI slots via
 * SlotRegistry, and listeners by calling `isActive()` before doing work — so
 * activation semantics live in exactly one place.
 */
class ModuleGate
{
    /** @var array<string, bool>|null */
    private ?array $gateable = null;

    public function __construct(
        private readonly BrandContext $context,
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

        $brand = $this->context->current();

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
