<?php

declare(strict_types=1);

namespace Konventix\Platform\Slots;

use Closure;
use Illuminate\Support\Collection;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;

/**
 * Enforcement point 3 of 4 for per-brand module activation (ADR-0003).
 *
 * A UI slot is a named extension point in a page or layout that modules can
 * contribute components to without the page knowing they exist. Registration is
 * unconditional at boot; the gate is applied when a slot is rendered.
 *
 * Contributions carry the Vue component name and its props — resolved through
 * the module's own `resources/js/`, never a hardcoded path in the shell.
 *
 * Props come in two shapes, for two kinds of slot. An admin card that fetches
 * its own numbers needs none, so it passes a literal array. A contribution to
 * a *public* page cannot work that way: a visitor's first paint must already
 * carry the call to action, and a client-side fetch would leave the page
 * without one until the request lands. Such a contribution passes a closure,
 * which the rendering controller resolves with the page's context — that is
 * the module computing its own props, in its own module, at render time. The
 * caller still knows nothing about what comes back.
 *
 * A contribution may also carry a per-brand feature (ADR-0047) — a second,
 * finer-grained gate for the module that has no module activation to hide
 * behind, applied the same way and after the module filter.
 *
 * Holds only boot-time registrations, so a long-lived Octane worker may share
 * this singleton across requests; `BrandContext` is scoped, not singleton, so
 * it is resolved at call time in `for()` rather than constructor-injected —
 * the same pattern `BlockRegistry::resolveActive()` uses.
 */
class SlotRegistry
{
    /** @var array<string, array<int, array{module: string, component: string, props: array<string, mixed>|Closure(array<string, mixed>): array<string, mixed>, order: int, feature: string|null}>> */
    private array $slots = [];

    public function __construct(private readonly ModuleGate $gate) {}

    /**
     * @param  array<string, mixed>|Closure(array<string, mixed>): array<string, mixed>  $props
     */
    public function add(
        string $slot,
        string $module,
        string $component,
        array|Closure $props = [],
        int $order = 100,
        ?string $feature = null,
    ): void {
        $this->slots[$slot][] = [
            'module' => $module,
            'component' => $component,
            'props' => $props,
            'order' => $order,
            'feature' => $feature,
        ];
    }

    /**
     * Contributions to a slot that are active for the current brand, with
     * every closure prop-bag resolved against `$context`.
     *
     * Resolution happens after the activation filter on purpose: a closure
     * belonging to a module this brand has switched off must never run, and
     * it is the only thing standing between an inactive module and a query
     * against tables the brand does not use.
     *
     * @param  array<string, mixed>  $context
     * @return Collection<int, array{module: string, component: string, props: array<string, mixed>, order: int, feature: string|null}>
     */
    public function for(string $slot, array $context = []): Collection
    {
        // Resolved here, not captured in the constructor: BrandContext is
        // scoped per request, and this registry is a singleton that outlives
        // it under Octane.
        $brand = app(BrandContext::class)->current();

        // PHPStan false positive on the return below: Collection's TValue is
        // a non-covariant template, and its invariance check rejects this
        // array shape — identical to the declared return type — purely
        // because the `feature` field is nullable. Confirmed by elimination
        // against several equivalent rewrites; the same shape with `feature`
        // typed non-nullable passes cleanly.
        // @phpstan-ignore return.type
        return Collection::make($this->slots[$slot] ?? [])
            ->filter(fn (array $entry): bool => $this->gate->isActive($entry['module']))
            ->filter(fn (array $entry): bool => $entry['feature'] === null
                || ($brand !== null && Feature::for($brand)->active($entry['feature'])))
            ->sortBy('order')
            ->map(fn (array $entry): array => [
                'module' => $entry['module'],
                'component' => $entry['component'],
                'props' => $entry['props'] instanceof Closure
                    ? ($entry['props'])($context)
                    : $entry['props'],
                'order' => $entry['order'],
                'feature' => $entry['feature'],
            ])
            ->values();
    }

    /**
     * Every slot name that has at least one registered contribution.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->slots);
    }
}
