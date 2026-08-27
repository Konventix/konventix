<?php

declare(strict_types=1);

namespace Konventix\Platform\Features;

use Closure;
use Konventix\Platform\Models\Brand;

/**
 * One per-brand Pennant feature a module offers with no module gate behind
 * it (ADR-0047) — Commerce's `commerce:shop` is the first: Commerce is Core
 * and cannot be switched off, so the shop opts in per brand on its own. The
 * brand page's Features card renders from this, the same way the cookie
 * dialog renders from `CookieDeclaration`.
 *
 * `$settingsComponent`/`$settingsProps` are the feature's own extension
 * point onto the brand edit page (ADR-0047 point 5, Commerce's
 * `commerce:waiting-room`): a Vue component name resolved the same
 * registered-component way `SlotRegistry` contributions are, and a
 * closure that computes its props from the explicit, route-bound `Brand`
 * — never `SlotRegistry` itself, whose feature gate reads the *ambient*
 * `BrandContext::current()`, which the admin host never resolves
 * (ADR-0029). `BrandAdminController::edit()` already holds the brand
 * explicitly and already computes each feature's Pennant state the same
 * way, so this is that same working mechanism, extended — not a new one.
 * Both are optional: a feature with nothing to configure declares neither.
 */
final readonly class BrandFeature
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $module,
        public ?string $settingsComponent = null,
        /** @var (Closure(Brand): array<string, mixed>)|null */
        public ?Closure $settingsProps = null,
    ) {}
}
