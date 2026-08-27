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
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Listeners\ProjectEligibilityOnRegistrationEvents;
use Konventix\Competition\Standings\Calculators\DoubleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\GroupStandingsCalculator;
use Konventix\Competition\Standings\Calculators\PointsStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SingleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SwissStandingsCalculator;
use Konventix\Competition\Standings\StandingsCalculatorRegistry;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;

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
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of('competition.tournament-admin', 'Tournament Admin', ...Permission::cases()),
        ];
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

        $this->app->singleton(StandingsCalculatorRegistry::class, function (): StandingsCalculatorRegistry {
            $registry = new StandingsCalculatorRegistry;

            $registry->register(new SingleEliminationStandingsCalculator);
            $registry->register(new DoubleEliminationStandingsCalculator);
            $registry->register(new PointsStandingsCalculator);
            $registry->register(new SwissStandingsCalculator);
            $registry->register(new GroupStandingsCalculator);

            return $registry;
        });
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

    /**
     * Same shape as Staffing's, and registered for the same reason.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new CompetitionGdprHandler);
    }
}
