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
 *
 * **A link may withdraw itself.** The closure returns `?string`, and `null`
 * means "not for this viewer after all": `HubPage` drops the entry instead
 * of rendering it. Visibility by module gate and capability is decided at
 * boot from what the registry knows, but some links depend on a fact only
 * the member's own data carries — Resources' *Mein Sitzplatz* points at a
 * seat claim, and a crew admission has no registration and therefore never
 * a claim. Without this the link rendered for everyone and led to a 404.
 * A link that renders through a `component` has no URL to withdraw; it is
 * always shown.
 */
final class HubLink
{
    /**
     * @param  string  $module  the owning module, used to gate visibility (ADR-0003)
     * @param  string  $key  stable identifier — 'support.helpdesk'
     * @param  string  $label  translation key or literal label
     * @param  (Closure(ActiveEventData, Authenticatable): ?string)|null  $url  resolved URL; null when `$component` renders the link itself, and a closure returning null withdraws the link for this viewer
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
