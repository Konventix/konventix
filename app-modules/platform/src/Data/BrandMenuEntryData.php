<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One rendered entry of the brand menu, as the shared `brandMenu` prop
 * carries it to the topbar (ADR-0009): already resolved for this viewer,
 * so the shell holds no visibility rule — a page the viewer may not read
 * is simply absent, and a parent with nothing left under it is too.
 *
 * `url` is null for a parent, which navigates nowhere itself. `external`
 * has the same meaning as on a `NavigationItem`: not an Inertia page,
 * render a plain anchor; `newTab` is the operator's choice on a URL entry.
 */
#[TypeScript]
final class BrandMenuEntryData extends Data
{
    /**
     * @param  array<int, BrandMenuEntryData>  $children
     */
    public function __construct(
        public readonly string $label,
        public readonly ?string $url,
        public readonly bool $external,
        public readonly bool $newTab,
        public readonly array $children,
    ) {}
}
