<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Platform\Brands\BrandContext;
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
 *
 * Its event is dispatched after the outermost commit, never inside the
 * transaction (design §6.1 step 7). Callers such as
 * `ReleaseBookingsForRegistration` and `ExpireResourceHolds` set the
 * booking's own brand in context for the duration of the release so
 * downstream listeners can read it ambiently; deferring the dispatch would
 * silently lose that brand once the caller's own `BrandContext::withBrand()`
 * restores whatever was there before, so whatever brand is ambient when the
 * event is built is captured and restored for the deferred callback alone.
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

            $event = new ResourceBookingReleased(
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
                ref: $resource->ref,
            );

            // Deferred to the outermost commit (design §6.1 step 7): SavePlan
            // and the seat assignment release inside their own transaction,
            // and a listener must never see a release the caller then rolls
            // back. Without an open transaction the callback runs at once.
            // Whatever brand is ambient right now (a caller such as
            // ReleaseBookingsForRegistration set it for this release) is
            // captured and restored around the deferred dispatch, because by
            // the time it runs the caller's own withBrand() has already put
            // the previous brand back.
            $brand = app(BrandContext::class)->current();

            DB::afterCommit(static function () use ($event, $brand): void {
                if ($brand === null) {
                    event($event);

                    return;
                }

                app(BrandContext::class)->withBrand($brand, static function () use ($event): void {
                    event($event);
                });
            });

            return $booking->refresh();
        });
    }
}
