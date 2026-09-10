<?php

declare(strict_types=1);

namespace Konventix\Resources\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Registration\Events\RegistrationAmended;
use Konventix\Registration\Events\RegistrationCancelled;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationRejected;
use Konventix\Resources\Listeners\FulfilClaimsOnRegistrationAmended;
use Konventix\Resources\Listeners\FulfilClaimsOnRegistrationConfirmed;
use Konventix\Resources\Listeners\ProjectEventWindow;
use Konventix\Resources\Listeners\ReleaseBookingsOnRegistrationCancelled;
use Konventix\Resources\Listeners\ReleaseBookingsOnRegistrationRejected;

/**
 * Resources is Pennant-gated: a brand that books nothing switches it off and
 * pays for none of it (ADR-0003).
 *
 * Three listener kinds, gated three ways on purpose. Claim fulfilment
 * short-circuits when the module is off for the brand the payload names —
 * an inactive module must not create bookings. The event-window projection
 * is never gated: a brand switching Resources on mid-event must find the
 * window already projected. The release listeners on cancellation and
 * rejection are never gated either (ADR-0060's explicit ADR-0003
 * exception): a release is safe and idempotent for a brand that switched
 * the module off, and a seat that stays booked for a cancelled
 * registration is the worse failure.
 */
class ResourcesServiceProvider extends ModuleServiceProvider
{
    public static function moduleName(): string
    {
        return 'resources';
    }

    protected function registerListeners(): void
    {
        Event::listen(
            RegistrationConfirmed::class,
            FulfilClaimsOnRegistrationConfirmed::class,
        );

        Event::listen(
            RegistrationAmended::class,
            FulfilClaimsOnRegistrationAmended::class,
        );

        Event::listen(RegistrationCancelled::class, ReleaseBookingsOnRegistrationCancelled::class);
        Event::listen(RegistrationRejected::class, ReleaseBookingsOnRegistrationRejected::class);

        Event::listen(EventCreated::class, [ProjectEventWindow::class, 'handleCreated']);
        Event::listen(EventRescheduled::class, [ProjectEventWindow::class, 'handleRescheduled']);
        Event::listen(EventDetailsUpdated::class, [ProjectEventWindow::class, 'handleDetailsUpdated']);
    }
}
