<?php

declare(strict_types=1);

namespace Konventix\Platform\Slots;

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
 */
class SlotRegistry
{
    /** @var array<string, array<int, array{module: string, component: string, props: array<string, mixed>, order: int}>> */
    private array $slots = [];

    public function __construct(private readonly ModuleGate $gate) {}

    /**
     * @param  array<string, mixed>  $props
     */
    public function add(
        string $slot,
        string $module,
        string $component,
        array $props = [],
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
     * Contributions to a slot that are active for the current brand.
     *
     * @return Collection<int, array{module: string, component: string, props: array<string, mixed>, order: int}>
     */
    public function for(string $slot): Collection
    {
        return Collection::make($this->slots[$slot] ?? [])
            ->filter(fn (array $entry): bool => $this->gate->isActive($entry['module']))
            ->sortBy('order')
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
