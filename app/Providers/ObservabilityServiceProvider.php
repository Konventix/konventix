<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Konventix\Identity\Models\User;
use Konventix\Platform\Enums\Permission;
use Laravel\Horizon\Horizon;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;

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
        $this->scheduleHousekeeping();
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
