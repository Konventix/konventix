<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Payload of the built-in `text` block: a heading and markdown body.
 */
#[TypeScript]
class TextBlockData extends Data
{
    public function __construct(
        public ?string $heading,
        public string $body,
    ) {}
}
