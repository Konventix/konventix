<?php

declare(strict_types=1);

namespace Konventix\Platform\Hub;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Konventix\Platform\Data\ActiveEventData;

/**
 * One quick link a module contributes to the live hub (design §5.2) — the
 * row of small actions under the cards.
 *
 * The URL is a closure, not a string, for the same reason `HubCard` carries
 * a payload closure: a link is registered once at boot (ADR-0003), but where
 * it points depends on the member's event, so it is resolved per request
 * and must resolve its own scoped bindings when it runs
 * (`.ai/rules/providers.md`). A link that needs more than a URL — a button
 * that opens a dialog, say — names a `component` instead and leaves `url`
 * null; the shell then renders that component in the link's place.
 */
final class HubLink
{
    /**
     * @param  string  $module  the owning module, used to gate visibility (ADR-0003)
     * @param  string  $key  stable identifier — 'support.helpdesk'
     * @param  string  $label  translation key or literal label
     * @param  (Closure(ActiveEventData, Authenticatable): string)|null  $url  resolved URL; null when `$component` renders the link itself
     * @param  string|null  $component  the Vue component that renders the link, when a plain anchor is not enough
     * @param  string|null  $icon  icon identifier the shell understands
     * @param  string|null  $permission  capability required to see the link (ADR-0013)
     * @param  int  $order  lower sorts first
     */
    public function __construct(
        public readonly string $module,
        public readonly string $key,
        public readonly string $label,
        public readonly ?Closure $url = null,
        public readonly ?string $component = null,
        public readonly ?string $icon = null,
        public readonly ?string $permission = null,
        public readonly int $order = 100,
    ) {}
}
