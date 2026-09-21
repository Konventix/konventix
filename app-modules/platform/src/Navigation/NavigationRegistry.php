<?php

declare(strict_types=1);

namespace Konventix\Platform\Navigation;

use Illuminate\Support\Collection;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;

/**
 * Enforcement point 2 of 4 for per-brand module activation (ADR-0003), and
 * one of the two enforcement points for per-brand features with no module
 * behind them (ADR-0047).
 *
 * Modules register entries from their own ServiceProvider at boot,
 * unconditionally. Filtering by what is active for the current brand happens
 * here, at render time — so a module never inspects feature state to decide
 * whether to register.
 *
 * Holds only boot-time registrations, so a long-lived Octane worker may share
 * this singleton across requests; `BrandContext` is scoped, not singleton, so
 * it is resolved at call time in `visible()` rather than constructor-injected
 * — the same pattern `BlockRegistry::resolveActive()` uses.
 *
 * An entry's optional badge count (D-SUP-13) obeys the same rule from the
 * other end: it is registered as a closure and resolved only when the entry
 * is serialised for one request (`NavigationItem::toArray()`), so nothing
 * here ever counts anything at boot and a worker cannot pin one request's
 * number into the next.
 */
class NavigationRegistry
{
    /** @var array<int, NavigationItem> */
    private array $items = [];

    public function __construct(private readonly ModuleGate $gate) {}

    public function add(NavigationItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    /**
     * Entries visible for the current context, in display order. Brand- and
     * account-area entries gate on module activation; admin-area entries
     * (ADR-0029) skip the brand gate — there is no brand on the admin host —
     * and rely on their capability.
     *
     * @return Collection<int, NavigationItem>
     */
    public function visible(string $area = NavigationItem::AREA_BRAND): Collection
    {
        // Resolved here, not captured in the constructor: BrandContext is
        // scoped per request, and this registry is a singleton that outlives
        // it under Octane.
        $brand = app(BrandContext::class)->current();

        return Collection::make($this->items)
            ->filter(fn (NavigationItem $item): bool => $item->area === $area)
            ->filter(fn (NavigationItem $item): bool => $area === NavigationItem::AREA_ADMIN
                || $this->gate->isActive($item->module))
            ->filter(fn (NavigationItem $item): bool => $item->feature === null
                || ($brand !== null && Feature::for($brand)->active($item->feature)))
            ->filter(fn (NavigationItem $item): bool => $item->permission === null
                || auth()->user()?->can($item->permission) === true)
            ->sortBy([
                fn (NavigationItem $a, NavigationItem $b): int => $a->order <=> $b->order,
                fn (NavigationItem $a, NavigationItem $b): int => strcmp($a->label, $b->label),
            ])
            ->values();
    }

    /**
     * Everything registered, gated or not. For diagnostics only — never render
     * from this.
     *
     * @return Collection<int, NavigationItem>
     */
    public function all(): Collection
    {
        return Collection::make($this->items);
    }
}
