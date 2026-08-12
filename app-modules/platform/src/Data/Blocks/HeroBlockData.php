<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Payload of the built-in `hero` block: a large opening heading with an
 * optional subheading, call-to-action link, and background/illustration
 * image.
 */
#[TypeScript]
class HeroBlockData extends Data
{
    public function __construct(
        public string $heading,
        public ?string $subheading,
        public ?string $ctaLabel,
        public ?string $ctaUrl,
        public ?string $imageUrl,
    ) {}
}
