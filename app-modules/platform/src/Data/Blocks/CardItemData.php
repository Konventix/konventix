<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One card of the `cards` block's grid (see {@see CardsBlockData}). `icon` is
 * a kebab-case key into the Vue component's fixed icon map — an unknown or
 * missing key simply renders no icon rather than failing the page.
 */
#[TypeScript]
class CardItemData extends Data
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $icon,
    ) {}
}
