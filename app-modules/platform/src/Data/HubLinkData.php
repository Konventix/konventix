<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One quick link under the hub's cards (design §7.1). Exactly one of `url`
 * and `component` is set: a plain anchor when the module gave a URL closure,
 * a module-shipped component when a link needs to do more than navigate.
 */
#[TypeScript]
final class HubLinkData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $url,
        public readonly ?string $component,
        public readonly ?string $icon,
    ) {}
}
