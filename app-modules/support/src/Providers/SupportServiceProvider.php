<?php

declare(strict_types=1);

namespace Konventix\Support\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Support\Console\PurgeSupportRequestsCommand;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Gdpr\SupportGdprHandler;
use Konventix\Support\Listeners\Metrics\RecordSupportMetrics;
use Konventix\Support\Metrics\SupportGauges;
use Konventix\Support\Metrics\SupportMetrics;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether a module is *active* is normally a per-brand
 * Pennant gate applied at four points — routes, navigation, UI slots,
 * listeners (ADR-0003).
 *
 * Support is the exception, and `$gateable = false` is the whole of it:
 * ADR-0063 makes the report channel kernel-style, always on for every brand
 * and on the operator console. Two facts forced it. `ModuleGate::isActive()`
 * reads "no brand in context" as inactive by design, and the console has no
 * brand — so a gated Support could never carry its launcher there, which is
 * exactly where the operator asked for it. And a brand that switched the
 * report channel off would save nothing and lose the one path by which its
 * visitors say the purchase page is broken.
 *
 * Never move any of this wiring into a central provider.
 */
class SupportServiceProvider extends ModuleServiceProvider
{
    /**
     * Kernel-style: always active for every brand and on the operator host,
     * never gated (ADR-0063, amending ADR-0022). Its routes therefore carry
     * no `ensure-module:support`.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'support';
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();
    }

    /**
     * ADR-0045: one starting point an operator gets per brand without
     * assembling capabilities by hand. All three, because triaging a report
     * without being able to read who sent it is not a job.
     *
     * Materialized by `permissions:sync` for every brand — and since
     * Support is always active, brand creation materializes it too
     * (ADR-0063's consequences).
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of(
                'support.desk',
                'Support Desk',
                Permission::ViewRequests,
                Permission::TriageRequests,
                Permission::ViewReporterContact,
            ),
        ];
    }

    /**
     * ADR-0059: Support's own metrics, from Support's own events. No
     * short-circuit on module activation here — the module cannot be
     * switched off (ADR-0063), so the usual fourth enforcement point has
     * nothing to enforce.
     */
    protected function registerListeners(): void
    {
        Event::listen(
            [SupportRequestOpened::class, SupportRequestStateChanged::class],
            RecordSupportMetrics::class,
        );
    }

    /**
     * The daily retention sweep (design §10.7, D-SUP-11).
     *
     * `withoutOverlapping()` because the first run after the window is
     * lengthened — or after an instance has been up long enough to have a
     * backlog — is the slow one, and a second sweep joining it would race
     * the same rows. Deferred through `callAfterResolving` so the scheduler
     * is only built in the role that runs it (ADR-0003).
     */
    protected function registerSchedule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeSupportRequestsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(PurgeSupportRequestsCommand::class)->dailyAt('03:20')->withoutOverlapping();
        });
    }

    /**
     * ADR-0059: the counters in the catalogue, the two gauges answered by
     * `SupportGauges` at scrape time.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...SupportMetrics::definitions());

        Prometheus::registerCollectorClasses([SupportGauges::class]);
    }

    /**
     * A guest report is personal data about somebody we never gave an
     * account, so this handler answers for an address-backed subject where
     * most modules cannot (ADR-0050 §3).
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new SupportGdprHandler);
    }
}
