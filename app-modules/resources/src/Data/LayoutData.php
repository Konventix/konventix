<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\LayoutKind;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A group's generator parameters (design §5): `count` places in a line, or
 * `rows × cols` in a grid, `spacing` millimetres apart (`rowSpacing` between
 * grid rows), numbered from `startIndex`. The generator runs in the editor
 * (`generator.ts`); the server only stores what it produced.
 */
#[TypeScript]
final class LayoutData extends Data
{
    public function __construct(
        #[Required]
        public readonly LayoutKind $type,

        #[Required, IntegerType, Min(1)]
        public readonly int $spacing,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $count = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $rows = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $cols = null,

        #[Nullable, IntegerType, Min(1)]
        public readonly ?int $rowSpacing = null,

        #[Required, IntegerType, Min(0)]
        public readonly int $startIndex = 1,
    ) {}
}
