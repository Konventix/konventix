<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * "Schedule all at" for one round (D-CMP-21). A round is `(bracket, round)`
 * because double elimination numbers its two brackets' rounds independently;
 * `bracket` is null for every format that has only one.
 */
final class ScheduleRoundData extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(1), Max(64)]
        public readonly int $round,

        #[Required, Date]
        public readonly string $scheduledAt,

        #[Nullable, StringType, Max(16)]
        public readonly ?string $bracket = null,
    ) {}

    public function scheduledAtInstant(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->scheduledAt)->utc();
    }
}
