<?php

declare(strict_types=1);

namespace Konventix\Platform\Slots;

use Closure;
use Illuminate\Support\Collection;
use Konventix\Platform\Modules\ModuleGate;

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
 */
class SlotRegistry
{
    /** @var array<string, array<int, array{module: string, component: string, props: array<string, mixed>|Closure(array<string, mixed>): array<string, mixed>, order: int}>> */
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
    ): void {
        $this->slots[$slot][] = [
            'module' => $module,
            'component' => $component,
            'props' => $props,
            'order' => $order,
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
     * @return Collection<int, array{module: string, component: string, props: array<string, mixed>, order: int}>
     */
    public function for(string $slot, array $context = []): Collection
    {
        return Collection::make($this->slots[$slot] ?? [])
            ->filter(fn (array $entry): bool => $this->gate->isActive($entry['module']))
            ->sortBy('order')
            ->map(fn (array $entry): array => [
                ...$entry,
                'props' => $entry['props'] instanceof Closure
                    ? ($entry['props'])($context)
                    : $entry['props'],
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
