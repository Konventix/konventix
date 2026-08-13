<?php

declare(strict_types=1);

namespace Konventix\Competition\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Brackets\Formats\DoubleEliminationGenerator;
use Konventix\Competition\Brackets\Formats\GroupStageGenerator;
use Konventix\Competition\Brackets\Formats\RoundRobinGenerator;
use Konventix\Competition\Brackets\Formats\SingleEliminationGenerator;
use Konventix\Competition\Brackets\Formats\SwissGenerator;
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

    /**
     * The bracket formats absorbed from LanBrackets (ADR-0019), registered
     * rather than switched on: a sixth format is one more `register()` call,
     * and LanBrackets' `race_heat`/`final_stage` stubs can arrive without
     * touching anything that already works.
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(FormatRegistry::class, function (): FormatRegistry {
            $registry = new FormatRegistry;

            $registry->register(new SingleEliminationGenerator);
            $registry->register(new DoubleEliminationGenerator);
            $registry->register(new RoundRobinGenerator);
            $registry->register(new SwissGenerator);
            $registry->register(new GroupStageGenerator);

            return $registry;
        });
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
