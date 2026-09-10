<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\ResourceBookingReleased;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Gives a booking's interval back.
 *
 * The row is kept and its state changed rather than deleted: the exclusion
 * constraint is partial over `held`/`confirmed`, so a released row stops
 * blocking the slot while the record of who held it survives. Deleting would
 * free the slot too, and lose the history for no gain.
 *
 * Idempotent — releasing an already-released booking is a no-op, because a
 * cancellation arriving twice is normal (a user click plus a queued job) and
 * must not emit twice.
 *
 * Reasons are plain strings owned by the caller: `registration_cancelled`,
 * `registration_rejected`, `plan_conflict`, `repicked`, `operator_released`,
 * `expired` (design doc §4.3).
 */
final class ReleaseResourceBooking
{
    public function handle(ResourceBooking $booking, BookingState $to = BookingState::Released, string $reason = 'released'): ResourceBooking
    {
        return DB::transaction(function () use ($booking, $to, $reason): ResourceBooking {
            if (! in_array($booking->state, [BookingState::Held, BookingState::Confirmed], true)) {
                return $booking;
            }

            $booking->forceFill(['state' => $to, 'hold_expires_at' => null])->save();

            $resource = $booking->resourceUnscoped();
            $type = $resource->resourceTypeUnscoped();

            event(new ResourceBookingReleased(
                bookingId: $booking->id,
                resourceId: $booking->resource_id,
                brandId: $booking->brand_id,
                holderType: $booking->holder_type,
                holderId: $booking->holder_id,
                reason: $reason,
                releasedAt: now()->toIso8601String(),
                eventId: $type->event_id,
                resourceTypeId: $type->id,
                registrationId: $booking->registration_id,
                claimId: $booking->claim_id,
                label: $booking->label ?? $resource->label,
                ref: null,
            ));

            return $booking->refresh();
        });
    }
}
