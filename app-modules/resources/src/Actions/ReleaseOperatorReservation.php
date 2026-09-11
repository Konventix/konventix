<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Events\PlaceSupplyChanged;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Gives an orga reservation back (design §7.3) with reason
 * `operator_released`. Refuses anything that is not an operator booking:
 * a registration's seat leaves only through the registration lifecycle or
 * a plan conflict, never through this button.
 *
 * Gives back the unit of the category's Registration quota the reservation
 * consumed (design §12b/#282): `PlaceSupplyChanged` fires with
 * `reason: released`, `direction: released` — but only when this call
 * actually released something. `ReleaseResourceBooking` is idempotent (a
 * booking that is already released is a no-op), and this event must be
 * exactly as idempotent, so it checks the booking's state itself rather
 * than trusting the return value.
 *
 * `Permission::BookOnBehalf` is checked by the console controller.
 */
final class ReleaseOperatorReservation
{
    public function __construct(
        private readonly ReleaseResourceBooking $release,
    ) {}

    public function handle(ResourceBooking $booking): ResourceBooking
    {
        if ($booking->holder_type !== ResourceBooking::HOLDER_OPERATOR) {
            throw new InvalidArgumentException(sprintf(
                'Booking [%s] is held by a %s, not an operator reservation.',
                $booking->id,
                $booking->holder_type,
            ));
        }

        $wasActive = in_array($booking->state, [BookingState::Held, BookingState::Confirmed], true);

        $released = $this->release->handle($booking, BookingState::Released, 'operator_released');

        if ($wasActive) {
            $resource = $released->resourceUnscoped();
            $type = $resource->resourceTypeUnscoped();

            // Idiom copied from `ReleaseResourceBooking`: whatever brand is
            // ambient now is captured and restored around the deferred
            // dispatch alone. `ReleaseResourceBooking` has already
            // committed its own transaction by the time this runs, so
            // `DB::afterCommit()` fires the callback at once here — kept
            // for consistency with every other emission site rather than
            // calling `event()` directly.
            $brand = app(BrandContext::class)->current();
            $event = new PlaceSupplyChanged(
                changeId: (string) Str::ulid(),
                brandId: $released->brand_id,
                eventId: $type->event_id,
                resourceTypeId: $type->id,
                reason: 'released',
                direction: 'released',
                places: 1,
            );

            DB::afterCommit(static function () use ($event, $brand): void {
                if ($brand === null) {
                    event($event);

                    return;
                }

                app(BrandContext::class)->withBrand($brand, static function () use ($event): void {
                    event($event);
                });
            });
        }

        return $released;
    }
}
