<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Konventix\Platform\Data\ActiveEventData;
use Spatie\LaravelData\Data;

/**
 * One card a module contributes to the live hub (design §5.2).
 *
 * Like `NavigationItem`, a module hands Platform a value object and knows
 * nothing about the page: the hub decides where the card sits, the module
 * decides what it says. The payload is a closure, never a value — a card is
 * registered once at boot (ADR-0003), but what it shows is a fact about one
 * member at one event, so it is computed only for the cards the operator's
 * layout keeps, and only inside a request. That is also why the closure
 * must resolve its own scoped bindings when it runs
 * (`.ai/rules/providers.md`): the registry holding it is a singleton that
 * outlives every request under Octane.
 */
final class HubCard
{
    /**
     * @param  string  $module  the owning module, used to gate visibility (ADR-0003)
     * @param  string  $key  stable identifier the operator's layout refers to — 'admission.my-ticket'
     * @param  string  $label  translation key or literal label
     * @param  string  $component  the Vue component that renders the card — 'admission/hub/MyTicketCard'
     * @param  Closure(ActiveEventData, Authenticatable): Data  $payload  the card's props for this member at this event
     * @param  string|null  $permission  capability required to see the card (ADR-0013)
     * @param  int  $order  lower sorts first
     */
    public function __construct(
        public readonly string $module,
        public readonly string $key,
        public readonly string $label,
        public readonly string $component,
        public readonly Closure $payload,
        public readonly ?string $permission = null,
        public readonly int $order = 100,
    ) {}
}
