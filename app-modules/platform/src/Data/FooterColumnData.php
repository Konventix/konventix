<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Data\Blocks\BlockData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One footer column: an optional heading and a small block composition —
 * the same editing model a static page uses, restricted to the block types
 * that make sense in a footer (slice 21b).
 */
#[TypeScript]
class FooterColumnData extends Data
{
    /**
     * @param  array<int, BlockData>  $blocks
     */
    public function __construct(
        #[Nullable, StringType, Max(60)]
        public ?string $heading,
        #[Present, ArrayType, Max(6), DataCollectionOf(BlockData::class)]
        public array $blocks,
    ) {}
}
