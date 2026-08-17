<?php

declare(strict_types=1);

namespace Konventix\Registration\Data;

use Konventix\Registration\Models\Quota;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The quota form (ADR-0009). Capacity only — `consumed` is a counter the
 * registration Actions move under a row lock, never a field an organizer
 * types over. Raising or lowering capacity is the lever they do have, and
 * `UpdateQuota` refuses to lower it below what is already taken.
 */
#[TypeScript]
final class QuotaData extends Data
{
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, IntegerType, Min(0)]
        public readonly int $capacity,
    ) {}

    public static function fromModel(Quota $quota): self
    {
        return new self(
            name: $quota->name,
            capacity: $quota->capacity,
        );
    }
}
