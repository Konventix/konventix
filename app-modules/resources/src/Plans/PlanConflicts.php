<?php

declare(strict_types=1);

namespace Konventix\Resources\Plans;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Konventix\Resources\Data\PlanConflictBookingData;
use Konventix\Resources\Data\PlanConflictData;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\ConflictReason;
use Konventix\Resources\Models\Plan;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Occupied places a save would delete, recategorise or block (design §6.1
 * step 4, §6.3). "Occupied" is a booking in `held` or `confirmed` — the
 * same predicate the exclusion constraint uses.
 *
 * Runs twice per save: once read-only before any lock, so a refused save
 * costs nothing, and once inside the transaction with `FOR UPDATE` on the
 * bookings, so a place booked in between still surfaces.
 */
final class PlanConflicts
{
    /**
     * @return list<PlanConflictData>
     */
    public function detect(Plan $plan, PlanDiffResult $diff, bool $lock = false): array
    {
        /** @var array<string, ConflictReason> $reasons */
        $reasons = [];

        foreach ($diff->removed as $id) {
            $reasons[$id] = ConflictReason::Deleted;
        }

        foreach ($diff->changed as $id => $fields) {
            if (in_array('resource_type_id', $fields, true)) {
                $reasons[$id] = ConflictReason::CategoryChanged;

                continue;
            }

            if (in_array('blocked', $fields, true) && $diff->rows[$id]['blocked'] === true) {
                $reasons[$id] = ConflictReason::Blocked;
            }
        }

        if ($reasons === []) {
            return [];
        }

        $query = ResourceBooking::withoutBrandScope()
            ->forBrand($plan->brand_id)
            ->whereIn('resource_id', array_keys($reasons))
            ->whereIn('state', BookingState::occupyingValues())
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        // groupBy()'s outer container is a plain Support\Collection, not an
        // Eloquent\Collection: Eloquent\Collection's TModel generic bound
        // requires a Model, which a nested Collection of bookings is not.
        /** @var SupportCollection<string, Collection<int, ResourceBooking>> $bookings */
        $bookings = $query->get()->groupBy('resource_id');

        $conflicts = [];

        foreach ($reasons as $placeId => $reason) {
            $occupying = $bookings->get($placeId);

            if ($occupying === null || $occupying->isEmpty()) {
                continue;
            }

            $existing = $diff->current[$placeId];

            $conflicts[] = new PlanConflictData(
                placeId: $placeId,
                ref: $existing->ref,
                label: $existing->label,
                reason: $reason,
                bookings: array_values($occupying->map(fn (ResourceBooking $booking): PlanConflictBookingData => new PlanConflictBookingData(
                    bookingId: $booking->id,
                    holderType: $booking->holder_type,
                    registrationId: $booking->registration_id,
                    claimId: $booking->claim_id,
                ))->all()),
            );
        }

        usort($conflicts, fn (PlanConflictData $a, PlanConflictData $b): int => strcmp($a->placeId, $b->placeId));

        return $conflicts;
    }
}
