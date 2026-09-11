<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;
use Konventix\Resources\Models\ResourceClaim;

/**
 * Whether the claim's own categories currently have a free place to choose.
 *
 * The operator's flagged note (seating design, end of §12, ahead of §12b):
 * "the picker shows 'no free places in your categories'" — a category that
 * has nothing free must read as its own explicit state, the way a sold-out
 * ticket type does, never as a red validation message on a field. The full
 * quota mechanism that would stop the *sale* itself is separate work
 * (§12b, issue #282).
 *
 * This backs only `SeatSummaryData.noFreePlaces` ("Meine Registrierung"),
 * which has no live poll to derive an answer from. It is **not** the read
 * the picker uses (corrected 2026-09-10, fix-wave item 2, #278; the design
 * document's §13 amendment describing a shared read is stale for the same
 * reason): `SeatPickerData` dropped its own `noFreePlaces` field during an
 * earlier review fix, and `Pick.vue` derives its "nothing free" banner
 * live from the polled availability map instead, through
 * `categoriesHaveFreePlace()` (`components/plan/eligibility.ts`) — see
 * `SeatPickController`'s docblock, which already says so correctly.
 *
 * The two surfaces genuinely can disagree, because they answer over
 * different row sets: this Action counts every *bookable* resource of the
 * claim's categories regardless of whether it sits on a plan at all, while
 * `categoriesHaveFreePlace()` only ever sees resources placed on a
 * *published plan of the claim's event*, because that is all
 * `ComputePlanAvailability` and the plan document it walks ever contain. A
 * seat category is expected to be entirely plan-placed, so nothing exposes
 * this today, but nothing enforces that expectation either — this is
 * recorded honestly rather than papered over as one shared source of
 * truth.
 *
 * Two reads, no relation magic: the claim's bookable places (published,
 * unblocked — `Resource::scopeBookable()`; soft-deleted rows already
 * excluded by the model's default query), then which of those carry an
 * occupying booking. Brand-scoped through `BelongsToBrand`: its one call
 * site runs on the brand host under the registrant's own session, never in
 * the console.
 */
final class HasFreePlaceForClaim
{
    public function handle(ResourceClaim $claim): bool
    {
        $bookable = Resource::query()
            ->bookable()
            ->whereIn('resource_type_id', $claim->resource_type_ids)
            ->pluck('id');

        if ($bookable->isEmpty()) {
            return false;
        }

        $occupied = ResourceBooking::query()
            ->whereIn('resource_id', $bookable)
            ->whereIn('state', BookingState::occupyingValues())
            ->pluck('resource_id');

        return $bookable->diff($occupied)->isNotEmpty();
    }
}
