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
 *
 * Deliberately carries no `noFreePlaces` flag (2026-09-10 review fix,
 * reversing the first draft of this batch): the picker already polls
 * `availability` every 15 s, so a "nothing free" boolean computed only at
 * render time would go stale the moment a held place lapsed or was
 * released while the person sat on the page — a same-page contradiction
 * between the banner and the plan it sits above. `Pick.vue` derives the
 * banner from the polled availability itself, through the pure
 * `categoriesHaveFreePlace()` (`components/plan/eligibility.ts`).
 * `SeatSummaryData.noFreePlaces` is the different case: "Meine
 * Registrierung" has no live poll to derive from, so it keeps the
 * server-computed answer from `HasFreePlaceForClaim`.
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
