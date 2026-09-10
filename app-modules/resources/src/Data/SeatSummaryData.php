<?php

declare(strict_types=1);

namespace Konventix\Resources\Data;

use Konventix\Resources\Models\ResourceClaim;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What the registrant's own page shows about their seat (design §7.4,
 * amendment §13 of 2026-09-10): the claim's state, how many places it
 * allows, and the refs of the places it currently holds. No holder names,
 * no plan geometry — the picker is the place for that, and the component
 * links there by registration id through Wayfinder rather than carrying a
 * URL.
 *
 * `noFreePlaces` (2026-09-10 batch amendment, ahead of design §12b/#282's
 * quota mechanism): the same `HasFreePlaceForClaim` read `SeatPickerData`
 * carries, so a category with nothing free reads identically here and on
 * the picker — never a bare "choose your seat" link into a dead end.
 */
#[TypeScript]
final class SeatSummaryData extends Data
{
    /**
     * @param  list<string>  $placeRefs
     */
    public function __construct(
        public readonly string $registrationId,
        public readonly string $claimState,
        public readonly int $quantity,
        public readonly array $placeRefs,
        public readonly bool $noFreePlaces,
    ) {}

    /**
     * Named `of*` rather than `from*` (.ai/rules/data.md).
     *
     * @param  list<string>  $placeRefs
     */
    public static function ofClaim(ResourceClaim $claim, array $placeRefs, bool $noFreePlaces): self
    {
        return new self(
            registrationId: $claim->registration_id,
            claimState: $claim->state->value,
            quantity: $claim->quantity,
            placeRefs: $placeRefs,
            noFreePlaces: $noFreePlaces,
        );
    }
}
