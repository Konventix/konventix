<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\PlaceState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One place as the console sees it (design §8.2 `occupancy`): the same
 * state the picker gets plus who holds it, by id. Names are resolved by the
 * module that owns them, never here (ADR-0004).
 */
#[TypeScript]
final class PlanOccupancyPlaceData extends Data
{
    public function __construct(
        public readonly PlaceState $state,
        public readonly ?string $holderType,
        public readonly ?string $registrationId,
        public readonly ?string $bookingId,
    ) {}
}
