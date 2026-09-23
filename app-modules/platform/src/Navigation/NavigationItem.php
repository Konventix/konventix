<?php

declare(strict_types=1);

namespace Konventix\Platform\Navigation;

use Closure;

/**
 * One navigation entry contributed by a module.
 *
 * LanCore's sidebar was a 1,285-line component that knew every domain. Here a
 * module hands Platform a value object and knows nothing about the shell.
 */
class NavigationItem
{
    public const AREA_BRAND = 'brand';

    public const AREA_ADMIN = 'admin';

    /**
     * The signed-in account's own things — "my registrations", "my orders".
     * A third area rather than a flag on the brand one: these entries belong
     * in the account menu, not the topbar, and they are only ever rendered
     * for a user who is already signed in, which is what makes it safe for
     * them to carry no `permission` of their own.
     */
    public const AREA_ACCOUNT = 'account';

    /**
     * @param  string  $module  the owning module, used to gate visibility
     * @param  string  $label  translation key or literal label
     * @param  string  $url  resolved URL; build it with Wayfinder helpers
     * @param  string|null  $icon  icon identifier the shell understands
     * @param  int  $order  lower sorts first
     * @param  string|null  $permission  capability required to see the entry
     * @param  string  $area  which shell shows it: brand topbar, account menu, or operator admin (ADR-0029)
     * @param  string|null  $feature  per-brand feature (ADR-0047) required to see the entry, on top of module activation
     * @param  bool  $external  the URL is not an Inertia page (Horizon, Pulse, the health page), so the shell must
     *                          render a plain anchor and let the browser load it whole — an Inertia visit would
     *                          fetch its HTML over XHR and show it in the error modal instead
     * @param  (Closure(): int)|null  $badge  a count the shell renders beside the label — "3 open" (D-SUP-13). A
     *                                        closure, never a number: a count is a fact about *this* request, and
     *                                        the item is registered once at boot (ADR-0003). Resolved by
     *                                        {@see self::toArray()} at serialisation time, which is also why the
     *                                        closure must resolve its own scoped bindings when it runs
     *                                        (`.ai/rules/providers.md`) — the registry that holds it is a
     *                                        singleton that outlives every request under Octane.
     */
    public function __construct(
        public readonly string $module,
        public readonly string $label,
        public readonly string $url,
        public readonly ?string $icon = null,
        public readonly int $order = 100,
        public readonly ?string $permission = null,
        public readonly string $area = self::AREA_BRAND,
        public readonly ?string $feature = null,
        public readonly bool $external = false,
        public readonly ?Closure $badge = null,
    ) {}

    /**
     * The badge count for this request, or null when the entry carries no
     * badge at all. Never called at boot: a count is a query, and a
     * navigation entry is registered long before there is a request to
     * count for.
     */
    public function badgeCount(): ?int
    {
        return $this->badge === null ? null : ($this->badge)();
    }

    /**
     * The shape the two shells read. `badge` is resolved here and nowhere
     * else — serialisation is the one moment a per-request count is both
     * wanted and cheap, and every caller of this method already stands in a
     * request. The label is translated here for the same reason: a module
     * registers it in English at boot, into a singleton, and only the
     * request knows its locale (ADR-0068).
     *
     * @return array{module: string, label: string, url: string, icon: string|null, order: int, external: bool, badge: int|null}
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'label' => __($this->label),
            'url' => $this->url,
            'icon' => $this->icon,
            'order' => $this->order,
            'external' => $this->external,
            'badge' => $this->badgeCount(),
        ];
    }
}
