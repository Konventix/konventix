<?php

declare(strict_types=1);

namespace Konventix\Event\Data\Blocks;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stored payload of the `countdown` static-page block: the event it counts
 * down to, and an optional label (e.g. "Doors open in"). The target instant
 * itself is computed server-side, see {@see CountdownResolvedData}.
 */
#[TypeScript]
final class CountdownBlockData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $label,
    ) {}
}
