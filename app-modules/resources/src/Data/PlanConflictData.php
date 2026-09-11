<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\ConflictReason;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A place the save would delete, recategorise or block while a booking in
 * state `held` or `confirmed` occupies it (design §6.3). Without a matching
 * `PlanConflictResolutionData` the save is refused with the list of these
 * (422 `{ conflicts }`) and nothing is written.
 */
#[TypeScript]
final class PlanConflictData extends Data
{
    /**
     * @param  list<PlanConflictBookingData>  $bookings
     */
    public function __construct(
        public readonly string $placeId,
        public readonly ?string $ref,
        public readonly string $label,
        public readonly ConflictReason $reason,

        /** @var list<PlanConflictBookingData> */
        #[DataCollectionOf(PlanConflictBookingData::class)]
        public readonly array $bookings,
    ) {}
}
