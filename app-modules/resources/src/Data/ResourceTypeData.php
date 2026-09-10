<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Enums\CategoryToken;
use Konventix\Resources\Models\ResourceType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The category form of the console (design §8.1): one source of validation,
 * props and TypeScript (ADR-0009). Uniqueness of the slug per event is the
 * table's unique index and the Action's job, not a rule here.
 */
#[TypeScript]
final class ResourceTypeData extends Data
{
    public function __construct(
        #[Required, Ulid]
        public readonly string $eventId,

        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255), Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
        public readonly string $slug,

        #[Required]
        public readonly CapacityMode $mode,

        #[Required, IntegerType, Min(0)]
        public readonly int $holdMinutes,

        #[Nullable, StringType, Max(2000)]
        public readonly ?string $description,

        #[Required]
        public readonly CategoryToken $token,

        #[Nullable, StringType, Max(32)]
        public readonly ?string $symbol,

        #[Required, IntegerType, Min(0)]
        public readonly int $sortOrder,

        #[Nullable]
        public readonly ?ShapeData $defaultShape,
    ) {}

    public static function fromModel(ResourceType $type): self
    {
        return new self(
            eventId: $type->event_id,
            name: $type->name,
            slug: $type->slug,
            mode: $type->mode,
            holdMinutes: $type->hold_minutes,
            description: $type->description,
            token: $type->token,
            symbol: $type->symbol,
            sortOrder: $type->sort_order,
            defaultShape: $type->default_shape,
        );
    }
}
