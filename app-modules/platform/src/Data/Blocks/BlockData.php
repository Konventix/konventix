<?php

declare(strict_types=1);

namespace Konventix\Platform\Data\Blocks;

use Konventix\Platform\Blocks\ValidatesBlockComposition;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One stored block: its type key and the payload that key's data class
 * validates (ADR-0009). The payload stays an array here on purpose — which
 * class validates it is the registry's answer, resolved at save time by
 * {@see ValidatesBlockComposition}.
 */
#[TypeScript]
final class BlockData extends Data
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        #[Required, StringType]
        public readonly string $type,
        #[Present, ArrayType]
        public readonly array $data,
    ) {}
}
