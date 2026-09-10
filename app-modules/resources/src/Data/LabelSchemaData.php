<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How a group names its places (design §5). Placeholders: `{row}` is the
 * group's label, `{n}` and `{n:02}` the 1-based index from `startIndex`,
 * `{col}` the column in a grid. The result goes into both `ref` and `label`
 * of every generated place.
 */
#[TypeScript]
final class LabelSchemaData extends Data
{
    public function __construct(
        #[Required, StringType, Max(64)]
        public readonly string $row = '{row}',

        #[Required, StringType, Max(64)]
        public readonly string $place = '{row}-{n:02}',
    ) {}
}
