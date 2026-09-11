<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\Quota;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Ulid;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The quota form (ADR-0009). Capacity is the lever an organizer has —
 * `consumed` is a counter the registration Actions move under a row lock,
 * never a field typed over, and `UpdateQuota` refuses to lower capacity
 * below what is already taken. `resourceTypeId` (design §12b, #282) is the
 * quota form's category select, a plain ULID with no FK (Resources owns
 * `ResourceType`, ADR-0014).
 */
#[TypeScript]
final class QuotaData extends Data
{
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, IntegerType, Min(0)]
        public readonly int $capacity,

        #[Nullable, Ulid]
        public readonly ?string $resourceTypeId = null,
    ) {}

    public static function fromModel(Quota $quota): self
    {
        return new self(
            name: $quota->name,
            capacity: $quota->capacity,
            resourceTypeId: $quota->resource_type_id,
        );
    }
}
