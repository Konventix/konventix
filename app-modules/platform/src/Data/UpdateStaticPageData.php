<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Konventix\Platform\Blocks\ValidatesBlockComposition;
use Konventix\Platform\Data\Blocks\BlockData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The page composer's save form (ADR-0009): title, slug, publish toggle and
 * the block composition. `$blocks` is validated per type by
 * {@see ValidatesBlockComposition}, not here — this only guards the
 * envelope.
 */
#[TypeScript]
final class UpdateStaticPageData extends Data
{
    /**
     * @param  array<int, BlockData>  $blocks
     */
    public function __construct(
        #[Required, StringType, Max(120)]
        public readonly string $title,
        #[Required, StringType, Max(80), Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        public readonly string $slug,
        #[BooleanType]
        public readonly bool $published,
        #[Present, ArrayType, Max(40), DataCollectionOf(BlockData::class)]
        public readonly array $blocks,
    ) {}
}
