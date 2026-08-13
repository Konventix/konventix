<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Enums\CapacityMode;
use Konventix\Resources\Events\ResourceBookingHeld;
use Konventix\Resources\Exceptions\ResourceUnavailableException;
use Konventix\Resources\Models\Resource;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Places a provisional hold on a resource for a half-open interval
 * `[startsAt, endsAt)` (ADR-0020).
 *
 * **Unit mode does not check for conflicts — it lets the database refuse.**
 * ADR-0020 chose an exclusion constraint precisely because a read-then-write
 * check loses to a race it cannot observe: two requests can both find a seat
 * free before either writes. So this Action attempts the insert and converts
 * the constraint violation into a domain exception. That is the opposite of
 * defensive checking, and it is deliberate.
 *
 * **Quantity mode adds a summed guard on top** (ADR-0034), because no
 * Postgres constraint can express "the sum of overlapping quantities stays
 * within capacity". The resource row is locked first so concurrent bookings
 * serialize; the row-level constraint still applies underneath. ADR-0034 is
 * candid that this invariant is weaker than unit mode's — it is an
 * acknowledged trade, not an oversight.
 */
final class HoldResourceBooking
{
    public function handle(
        Resource $resource,
        string $holderType,
        string $holderId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $quantity = 1,
        ?string $registrationId = null,
    ): ResourceBooking {
        return DB::transaction(function () use ($resource, $holderType, $holderId, $startsAt, $endsAt, $quantity, $registrationId): ResourceBooking {
            $type = $resource->resourceType()->withoutGlobalScopes()->firstOrFail();

            if ($type->mode === CapacityMode::Quantity) {
                $this->guardRemainingCapacity($resource, $startsAt, $endsAt, $quantity);
            }

            $booking = new ResourceBooking([
                'resource_id' => $resource->id,
                'holder_type' => $holderType,
                'holder_id' => $holderId,
                'registration_id' => $registrationId,
                'quantity' => $quantity,
                // Unit-mode bookings are exclusive, so the constraint guards
                // them; quantity-mode ones share the interval by design and
                // are guarded by the summed check above instead (ADR-0034).
                'is_exclusive' => $type->mode === CapacityMode::Unit,
                'state' => BookingState::Held,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'hold_expires_at' => now()->addMinutes($type->hold_minutes),
            ]);
            // brand_id from the resource, not ambient context (ADR-0010).
            $booking->forceFill(['brand_id' => $resource->brand_id]);

            try {
                $booking->save();
            } catch (QueryException $e) {
                // 23P01 — exclusion_violation. The constraint did its job;
                // anything else is a real failure and must not be swallowed.
                if (($e->getCode() === '23P01')
                    || str_contains($e->getMessage(), 'resource_bookings_no_overlap')
                ) {
                    throw ResourceUnavailableException::alreadyBooked($resource->id);
                }

                throw $e;
            }

            event(new ResourceBookingHeld(
                bookingId: $booking->id,
                resourceId: $resource->id,
                brandId: $resource->brand_id,
                holderType: $holderType,
                holderId: $holderId,
                quantity: $quantity,
                startsAt: $startsAt->toIso8601String(),
                endsAt: $endsAt->toIso8601String(),
                holdExpiresAt: $booking->hold_expires_at->toIso8601String(),
            ));

            return $booking;
        });
    }

    /**
     * Quantity mode's summed overlap check (ADR-0034). Locks the resource row
     * first so two concurrent bookings cannot both read the same remaining
     * capacity and both pass.
     *
     * The interval test is `starts_at < :ends AND ends_at > :starts`, which is
     * exactly half-open overlap: a booking ending when another begins does not
     * collide.
     */
    private function guardRemainingCapacity(
        Resource $resource,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $quantity,
    ): void {
        Resource::withoutBrandScope()->whereKey($resource->id)->lockForUpdate()->firstOrFail();

        $booked = (int) ResourceBooking::withoutBrandScope()
            ->where('resource_id', $resource->id)
            ->whereIn('state', BookingState::occupyingValues())
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->sum('quantity');

        $remaining = $resource->capacity - $booked;

        if ($quantity > $remaining) {
            throw ResourceUnavailableException::insufficientCapacity($resource->id, $quantity, max(0, $remaining));
        }
    }
}
