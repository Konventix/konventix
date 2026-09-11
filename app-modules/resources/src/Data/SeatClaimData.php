<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\ResourceClaim;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The claim the picker is placing (design §7.4): which categories, how many
 * places, and where it stands.
 */
#[TypeScript]
final class SeatClaimData extends Data
{
    /**
     * @param  list<string>  $resourceTypeIds
     */
    public function __construct(
        public readonly string $id,

        /** @var list<string> */
        public readonly array $resourceTypeIds,

        public readonly SeatingMode $seatingMode,
        public readonly int $quantity,
        public readonly ClaimState $state,
    ) {}

    public static function fromModel(ResourceClaim $claim): self
    {
        return new self(
            id: $claim->id,
            resourceTypeIds: $claim->resource_type_ids,
            seatingMode: $claim->seating_mode,
            quantity: $claim->quantity,
            state: $claim->state,
        );
    }
}
