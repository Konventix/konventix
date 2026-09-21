<?php

declare(strict_types=1);

namespace Konventix\Event\Data;

use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One place around a venue — a parking spot or a loading zone (SPEC.md
 * "Owns" — Venue). A venue's `parking_spots` and `loading_zones` are each
 * a list of these, decoded/encoded by
 * `Konventix\Event\Models\Casts\VenuePlacesCast`. Coordinates are optional:
 * a place with none is a label and an address, one with both gets an
 * "open in maps" link on the public page.
 */
#[TypeScript]
final class VenuePlaceData extends Data
{
    public function __construct(
        #[Required, StringType, Max(255)]
        public readonly string $label,

        #[Nullable, StringType, Max(255)]
        public readonly ?string $address = null,

        #[Nullable, Numeric, Between(-90, 90)]
        public readonly ?float $latitude = null,

        #[Nullable, Numeric, Between(-180, 180)]
        public readonly ?float $longitude = null,
    ) {}
}
