<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/**
 * One opening period of a venue's site (SPEC.md "Owns" — Venue's "opening
 * times of the site"). A venue's `opening_times` is a list of these,
 * decoded/encoded by `Konventix\Event\Models\Casts\OpeningTimesCast`.
 */
final class OpeningPeriodData extends Data
{
    public function __construct(
        /**
         * ISO-8601 day-of-week number: 1 (Monday) through 7 (Sunday).
         */
        #[Required, IntegerType, Between(1, 7)]
        public readonly int $dayOfWeek,

        #[Required, DateFormat('H:i')]
        public readonly string $opensAt,

        #[Required, DateFormat('H:i')]
        public readonly string $closesAt,
    ) {}
}
