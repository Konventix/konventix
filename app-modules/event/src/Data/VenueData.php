<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Attributes\Validation\Uppercase;
use Spatie\LaravelData\Data;

/**
 * Single source of validation rules, Inertia props shape, and (eventually)
 * generated TypeScript for the venue form (ADR-0009). No TypeScript
 * generation is wired yet — see TODO.md.
 */
final class VenueData extends Data
{
    /**
     * @param  array<int, OpeningPeriodData>  $openingTimes
     */
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $name,

        #[Required, StringType, Max(255)]
        public readonly string $addressLine1,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $addressLine2,

        #[Required, StringType, Max(20)]
        public readonly string $postalCode,

        #[Required, StringType, Max(255)]
        public readonly string $city,

        #[Required, StringType, Size(2), Uppercase]
        public readonly string $countryCode,

        #[Nullable, Numeric, Between(-90, 90)]
        public readonly ?float $latitude,

        #[Nullable, Numeric, Between(-180, 180)]
        public readonly ?float $longitude,

        #[Nullable, StringType]
        public readonly ?string $accessNotes,

        #[DataCollectionOf(OpeningPeriodData::class)]
        public readonly array $openingTimes = [],
    ) {}
}
