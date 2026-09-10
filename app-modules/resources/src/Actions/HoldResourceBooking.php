<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
 *
 * Its event is dispatched after the outermost commit, never inside the
 * transaction (design §6.1 step 7).
 */
final class HoldResourceBooking
{
    /**
     * @param  string|null  $claimId  the seat claim this booking fulfils (S3)
     * @param  string|null  $amendmentId  the registration amendment that produced it — idempotency key with `registrationId`
     * @param  string|null  $label  snapshot label; defaults to the resource's current label
     */
    public function handle(
        Resource $resource,
        string $holderType,
        string $holderId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $quantity = 1,
        ?string $registrationId = null,
        ?string $claimId = null,
        ?string $amendmentId = null,
        ?string $label = null,
    ): ResourceBooking {
        return DB::transaction(function () use ($resource, $holderType, $holderId, $startsAt, $endsAt, $quantity, $registrationId, $claimId, $amendmentId, $label): ResourceBooking {
            $type = $resource->resourceTypeUnscoped();

            // A unit is one thing. Booking "two" of it would write one row
            // with quantity 2 — one seat for a registration that paid for
            // two — so a caller that wants n seats makes n holds. This is a
            // programming error, not an availability outcome, hence not
            // ResourceUnavailableException: claim fulfilment must not skip
            // past it as "taken, try the next seat".
            if ($type->mode === CapacityMode::Unit && $quantity !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Resource [%s] is a unit; %d units are %d separate bookings.',
                    $resource->id,
                    $quantity,
                    $quantity,
                ));
            }

            if ($type->mode === CapacityMode::Quantity) {
                $this->guardRemainingCapacity($resource, $startsAt, $endsAt, $quantity);
            }

            $booking = new ResourceBooking([
                'resource_id' => $resource->id,
                'holder_type' => $holderType,
                'holder_id' => $holderId,
                'registration_id' => $registrationId,
                'claim_id' => $claimId,
                'amendment_id' => $amendmentId,
                'quantity' => $quantity,
                'label' => $label ?? $resource->label,
                // Unit-mode bookings are exclusive, so the constraint guards
                // them; quantity-mode ones share the interval by design and
                // are guarded by the summed check above instead (ADR-0034).
                'is_exclusive' => $type->mode === CapacityMode::Unit,
                'state' => BookingState::Held,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                // A type with hold_minutes 0 holds without a clock: the
                // caller confirms in the same transaction (seat picks, S3).
                'hold_expires_at' => $type->hold_minutes > 0 ? now()->addMinutes($type->hold_minutes) : null,
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

            $event = new ResourceBookingHeld(
                bookingId: $booking->id,
                resourceId: $resource->id,
                brandId: $resource->brand_id,
                holderType: $holderType,
                holderId: $holderId,
                quantity: $quantity,
                startsAt: $startsAt->toIso8601String(),
                endsAt: $endsAt->toIso8601String(),
                holdExpiresAt: $booking->hold_expires_at?->toIso8601String(),
                eventId: $type->event_id,
                resourceTypeId: $type->id,
                registrationId: $registrationId,
                claimId: $claimId,
                label: $booking->label ?? $resource->label,
                ref: $resource->ref,
            );

            // Deferred to the outermost commit (design §6.1 step 7): SavePlan
            // and the seat assignment release inside their own transaction,
            // and a listener must never see a release the caller then rolls
            // back. Without an open transaction the callback runs at once.
            DB::afterCommit(static function () use ($event): void {
                event($event);
            });

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
