<?php

declare(strict_types=1);

namespace Konventix\Resources\Actions;

use InvalidArgumentException;
use Konventix\Resources\Enums\BookingState;
use Konventix\Resources\Models\ResourceBooking;

/**
 * Gives an orga reservation back (design §7.3) with reason
 * `operator_released`. Refuses anything that is not an operator booking:
 * a registration's seat leaves only through the registration lifecycle or
 * a plan conflict, never through this button.
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

        return $this->release->handle($booking, BookingState::Released, 'operator_released');
    }
}
