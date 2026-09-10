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
 * (§12b, issue #282); until it exists, this is the one read both surfaces
 * that need the answer share — `SeatPickerData.noFreePlaces` (the picker)
 * and `SeatSummaryData.noFreePlaces` ("Meine Registrierung") — so the two
 * pages never say something different about the same claim.
 *
 * Two reads, no relation magic, mirroring `ComputePlanAvailability`'s own
 * shape: the claim's bookable places (published, unblocked —
 * `Resource::scopeBookable()`; soft-deleted rows already excluded by the
 * model's default query), then which of those carry an occupying booking.
 * Brand-scoped through `BelongsToBrand`: both call sites run on the brand
 * host under the registrant's own session, never in the console.
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
