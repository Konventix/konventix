<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The props of `seating/Pick` (design §7.4): the claim being placed, the
 * categories it may choose from, every published plan of the event with its
 * document, the availability snapshot, and the places the registration
 * holds right now.
 */
#[TypeScript]
final class SeatPickerData extends Data
{
    /**
     * @param  list<CategoryData>  $categories
     * @param  list<PlanViewData>  $plans
     * @param  list<string>  $currentPlaceIds
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly SeatClaimData $claim,

        /** @var list<CategoryData> */
        #[DataCollectionOf(CategoryData::class)]
        public readonly array $categories,

        /** @var list<PlanViewData> */
        #[DataCollectionOf(PlanViewData::class)]
        public readonly array $plans,

        public readonly PlanAvailabilityData $availability,

        /** @var list<string> */
        public readonly array $currentPlaceIds,
    ) {}
}
