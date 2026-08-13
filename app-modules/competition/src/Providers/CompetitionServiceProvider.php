<?php

declare(strict_types=1);

namespace Konventix\Competition\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Competition\Listeners\ProjectEligibilityOnRegistrationEvents;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;
use Laravel\Pennant\Feature;

/**
 * Competition is Pennant-gated: a brand that runs no tournaments switches it
 * off and pays for none of it (ADR-0003).
 *
 * The eligibility projection is deliberately *not* gated — a brand switching
 * Competition on mid-event must find its existing registrations already
 * projected, or nobody could enter anything until they all re-confirmed.
 */
class CompetitionServiceProvider extends ModuleServiceProvider
{
    public static function moduleName(): string
    {
        return 'competition';
    }

    protected function registerFeatures(): void
    {
        // Off by default: running tournaments is an opt-in.
        Feature::define(self::moduleName(), fn (): bool => false);
    }

    protected function registerListeners(): void
    {
        Event::listen(
            RegistrationSubmitted::class,
            [ProjectEligibilityOnRegistrationEvents::class, 'handleSubmitted'],
        );

        Event::listen(
            RegistrationConfirmed::class,
            [ProjectEligibilityOnRegistrationEvents::class, 'handleConfirmed'],
        );
    }
}
