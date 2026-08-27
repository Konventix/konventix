<?php

declare(strict_types=1);

namespace Konventix\Platform\Features;

/**
 * One per-brand Pennant feature a module offers with no module gate behind
 * it (ADR-0047) — Commerce's `commerce:shop` is the first: Commerce is Core
 * and cannot be switched off, so the shop opts in per brand on its own. The
 * brand page's Features card renders from this, the same way the cookie
 * dialog renders from `CookieDeclaration`.
 */
final readonly class BrandFeature
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $module,
    ) {}
}
