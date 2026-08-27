<?php

declare(strict_types=1);

namespace Konventix\Resources\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Resources\Listeners\FulfilClaimsOnRegistrationConfirmed;
use Konventix\Resources\Listeners\ProjectEventWindow;

/**
 * Resources is Pennant-gated: a brand that books nothing switches it off and
 * pays for none of it (ADR-0003).
 *
 * The two listener kinds are gated differently on purpose. Claim fulfilment
 * short-circuits when the module is inactive — an inactive module must not
 * create bookings. The event-window projection does not: a brand switching
 * Resources on mid-event must find the window already projected, or its first
 * booking would have no interval to book against. Keeping a read model current
 * while the feature is off costs one row and removes a whole class of
 * "switched it on and it didn't work" problems.
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

        Event::listen(EventCreated::class, [ProjectEventWindow::class, 'handleCreated']);
        Event::listen(EventRescheduled::class, [ProjectEventWindow::class, 'handleRescheduled']);
    }
}
