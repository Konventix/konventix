<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The console's occupancy overlay for one plan (design §8.2), keyed by
 * element ULID.
 */
#[TypeScript]
final class PlanOccupancyData extends Data
{
    /**
     * @param  array<string, PlanOccupancyPlaceData>  $places
     */
    public function __construct(
        /** @var array<string, PlanOccupancyPlaceData> */
        #[DataCollectionOf(PlanOccupancyPlaceData::class)]
        public readonly array $places,
    ) {}
}
