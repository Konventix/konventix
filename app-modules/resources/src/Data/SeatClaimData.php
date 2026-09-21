<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Enums\ClaimState;
use Konventix\Resources\Enums\SeatingMode;
use Konventix\Resources\Models\ResourceClaim;
use Konventix\Resources\Models\ResourceEventWindow;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The claim the picker is placing (design §7.4): which categories, how many
 * places, and where it stands.
 *
 * `eventName`/`eventSlug` come from `ResourceEventWindow`, Resources' own
 * projection of Event's contract events (ADR-0004) — the picker names the
 * event in its heading and links it to the landing page (bugfix round
 * 2026-09-21, Task 17b). Both null when no window has been projected yet;
 * the page then falls back to the bare title.
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
        public readonly ?string $eventName,
        public readonly ?string $eventSlug,
    ) {}

    public static function fromModel(ResourceClaim $claim, ?ResourceEventWindow $window = null): self
    {
        return new self(
            id: $claim->id,
            resourceTypeIds: $claim->resource_type_ids,
            seatingMode: $claim->seating_mode,
            quantity: $claim->quantity,
            state: $claim->state,
            eventName: $window?->name,
            eventSlug: $window?->slug,
        );
    }
}
