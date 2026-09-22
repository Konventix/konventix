<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Konventix\Platform\Modules\ModuleGate;

/**
 * Cards and links modules contribute to the live hub (design §5.2). A
 * sibling of `NavigationRegistry`: modules register from their own
 * ServiceProvider at boot, unconditionally, and filtering by module
 * activation (ADR-0003) and capability (ADR-0013) happens here, at render
 * time — so a module never inspects feature state to decide whether to
 * register.
 *
 * Holds only boot-time registrations, so a long-lived Octane worker may
 * share this singleton across requests; `ModuleGate::isActive()` resolves
 * the scoped `BrandContext` itself at call time (`.ai/rules/providers.md`).
 *
 * Payload and URL closures are never run here — `HubPage` runs them for the
 * cards the operator's layout keeps, so a hidden card costs nothing.
 *
 * The viewer is passed in rather than read from `auth()`: the hub is
 * rendered for exactly one signed-in member, and typing the parameter as
 * `Authenticatable` keeps Platform from importing Identity's model
 * (ADR-0004) — the capability check goes through `Gate::forUser()`.
 */
final class HubRegistry
{
    /** @var list<HubCard|HubLink> */
    private array $items = [];

    public function __construct(private readonly ModuleGate $gate) {}

    public function add(HubCard|HubLink ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
    }

    /**
     * Cards the viewer may see for the current brand, in display order.
     *
     * @return Collection<int, HubCard>
     */
    public function visibleCards(Authenticatable $user): Collection
    {
        /** @var Collection<int, HubCard> */
        return $this->visible($user)->filter(fn (HubCard|HubLink $item): bool => $item instanceof HubCard)->values();
    }

    /**
     * Links the viewer may see for the current brand, in display order.
     *
     * @return Collection<int, HubLink>
     */
    public function visibleLinks(Authenticatable $user): Collection
    {
        /** @var Collection<int, HubLink> */
        return $this->visible($user)->filter(fn (HubCard|HubLink $item): bool => $item instanceof HubLink)->values();
    }

    /**
     * Everything registered, gated or not. For diagnostics only — never render
     * from this.
     *
     * @return Collection<int, HubCard|HubLink>
     */
    public function all(): Collection
    {
        return Collection::make($this->items);
    }

    /** @return Collection<int, HubCard|HubLink> */
    private function visible(Authenticatable $user): Collection
    {
        return Collection::make($this->items)
            ->filter(fn (HubCard|HubLink $item): bool => $this->gate->isActive($item->module))
            ->filter(fn (HubCard|HubLink $item): bool => $item->permission === null
                || Gate::forUser($user)->allows($item->permission))
            ->sortBy([
                fn (HubCard|HubLink $a, HubCard|HubLink $b): int => $a->order <=> $b->order,
                fn (HubCard|HubLink $a, HubCard|HubLink $b): int => strcmp($a->label, $b->label),
            ])
            ->values();
    }
}
