<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Konventix\Commerce\Health\WaitingRoomCheck;
use Konventix\Identity\Models\User;
use Konventix\Platform\Enums\Permission;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * Wiring for the operations dashboards — Pulse, Horizon and, in development,
 * Telescope.
 *
 * Host-level rather than module-level, and that is a boundary decision rather
 * than laziness. ADR-0003 says a *module* registers itself and is never wired
 * centrally; these are deployment tooling, not modules, and no module should
 * grow a dependency on whether this deployment happens to have Telescope
 * installed. The host composes them, the way it composes the demo seed.
 *
 * **Access is a capability, in every environment.** Each of these three ships
 * an authorization default of `app()->environment('local')`, which fails in
 * two directions at once: in production nobody can open them however the roles
 * are configured, and in development they are open to anyone who can reach the
 * host, with no login at all. Both are replaced below, so who may open a
 * dashboard is a role decision like everything else in the console (ADR-0013).
 *
 * Horizon and Telescope do not consult their gate from the middleware — they
 * call their own `auth()` callback, which is normally installed by an
 * application provider published by `horizon:install`. Those providers are not
 * published here, so the callbacks are set explicitly; defining the gates
 * alone would leave both dashboards on their local-only default.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Telescope is a `require-dev` package and excluded from package
        // discovery, so production never sees it: `composer install --no-dev`
        // leaves the class absent and this returns early. Registered by hand
        // rather than discovered so that stays true.
        if ($this->app->environment('production') || ! class_exists(TelescopeServiceProvider::class)) {
            return;
        }

        $this->app->register(TelescopeServiceProvider::class);

        $this->scrubTelescopeEntries();
    }

    /**
     * Telescope's published provider does this only outside `local`, on the
     * reasoning that a developer's own machine holds nothing worth hiding.
     * That reasoning does not survive contact with this application: local
     * databases here are seeded by `konventix:seed` with registrations,
     * invoices and subject requests, and a developer sharing a screen should
     * not be one click from somebody's session cookie.
     *
     * So the scrubbing is unconditional. Telescope never runs in production
     * at all (it is a `require-dev` package), which makes this the only
     * environment where it could leak anything.
     */
    private function scrubTelescopeEntries(): void
    {
        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'current_password']);

        Telescope::hideRequestHeaders(['cookie', 'x-csrf-token', 'x-xsrf-token', 'authorization']);
    }

    public function boot(): void
    {
        $this->authorizePulse();
        $this->authorizeHorizon();
        $this->authorizeTelescope();
        $this->registerHealthChecks();
        $this->scheduleHousekeeping();
    }

    /**
     * What `/up` cannot tell you.
     *
     * Laravel's built-in health route proves PHP answered, which is exactly
     * the failure mode that never happens: the interesting outage is Postgres
     * refusing connections, Redis full, the scheduler dead, or the disk
     * finished — every one of which leaves the web role answering `/up`
     * cheerfully.
     *
     * `ScheduleCheck` needs `health:schedule-check-heartbeat` running on the
     * schedule to have anything to report, which is wired below; without it it
     * reports the scheduler as down, correctly, since nothing is ticking.
     */
    private function registerHealthChecks(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(5),
            HorizonCheck::new(),
            // ADR-0047 point 5, Task 13: the waiting room's admission tick
            // is a Horizon queue worker like any other, but "Horizon is
            // running" (the check above) does not prove THIS supervisor is
            // draining its queue — WaitingRoomCheck reads the tick's own
            // heartbeat.
            WaitingRoomCheck::new(),
            UsedDiskSpaceCheck::new()->warnWhenUsedSpaceIsAbovePercentage(80)->failWhenUsedSpaceIsAbovePercentage(90),
            // Debug mode in production leaks stack traces, environment and
            // query contents to whoever triggers an error.
            DebugModeCheck::new(),
        ]);
    }

    /**
     * Two of these are load-bearing rather than tidy.
     *
     * `horizon:snapshot` is what fills the metrics dashboard at all — running
     * the Horizon process alone leaves it permanently blank, which reads as
     * "Horizon is broken" rather than "nobody scheduled the snapshot".
     *
     * `telescope:prune` and Pulse's trim keep two stores of personal data from
     * growing without bound. Telescope holds request bodies and Pulse holds
     * user ids, and neither is covered by Compliance's retention rules
     * (D-CPL-01 names the delivery log and the audit trail). Until they are,
     * these windows are the only thing bounding them — deliberately shorter
     * than anything Compliance governs, because nothing here is evidence of
     * anything.
     */
    private function scheduleHousekeeping(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('horizon:snapshot')->everyFiveMinutes();

            $schedule->command('pulse:trim')->hourly();

            if (class_exists(Telescope::class)) {
                $schedule->command('telescope:prune --hours=48')->daily();
            }

            // Health results are stored, not computed on request: an external
            // monitor polling every thirty seconds must not run a disk scan
            // and a Redis round-trip each time.
            $schedule->command('health:check')->everyFiveMinutes();
            $schedule->command('health:schedule-check-heartbeat')->everyMinute();

            // Off-site copies. GoBD keeps archived invoices for ten years
            // (ADR-0031, §147 AO), which is far longer than any single disk
            // should be trusted with them.
            $schedule->command('backup:clean')->dailyAt('01:00');
            $schedule->command('backup:run')->dailyAt('01:30');
        });
    }

    /**
     * Pulse does read its gate, but registers its own local-only default
     * through `callAfterResolving`. Defining ours through the facade resolves
     * the Gate first, which drains that pending callback, so this definition
     * lands last and wins.
     */
    private function authorizePulse(): void
    {
        Gate::define('viewPulse', fn (?User $user): bool => $user?->can(Permission::ViewDiagnostics->value) ?? false);
    }

    private function authorizeHorizon(): void
    {
        Gate::define('viewHorizon', fn (?User $user): bool => $user?->can(Permission::ViewDiagnostics->value) ?? false);

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Held apart from the other two on purpose. Telescope records request
     * bodies, so opening it reads other people's personal data — registrations,
     * subject requests, invoice recipients — rather than metrics about how busy
     * the system is. A role may reasonably hold one capability and not this one.
     */
    private function authorizeTelescope(): void
    {
        if (! class_exists(Telescope::class)) {
            return;
        }

        Gate::define('viewTelescope', fn (?User $user): bool => $user?->can(Permission::ViewRequestLog->value) ?? false);

        Telescope::auth(fn (Request $request): bool => Gate::check('viewTelescope', [$request->user()]));
    }
}
