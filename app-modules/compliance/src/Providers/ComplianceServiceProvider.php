<?php

declare(strict_types=1);

namespace Konventix\Compliance\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Http\Middleware\EnsurePoliciesAccepted;
use Konventix\Compliance\Listeners\DispatchSubjectRequestFanOut;
use Konventix\Compliance\Support\PolicyMarkdown;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class ComplianceServiceProvider extends ModuleServiceProvider
{
    /**
     * Kernel module: always active for every brand, never gated.
     */
    protected bool $gateable = false;

    /**
     * The module's Pennant feature name and route-middleware key.
     */
    public static function moduleName(): string
    {
        return 'compliance';
    }

    /**
     * GdprHandlerRegistry holds only boot-time registrations (ADR-0003),
     * which a long-lived Octane worker may safely share across requests —
     * same reasoning as Platform's NavigationRegistry. PolicyMarkdown is
     * stateless and builds a CommonMark environment once, which is exactly
     * what a long-lived worker should reuse.
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(GdprHandlerRegistry::class);
        $this->app->singleton(PolicyMarkdown::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The consent gate as an opt-in alias other modules apply to their
        // own routes (see EnsurePoliciesAccepted). Compliance registers the
        // alias and wires nothing centrally — ADR-0003.
        $this->app->make(Router::class)
            ->aliasMiddleware('require-consent', EnsurePoliciesAccepted::class);
    }

    /**
     * The fan-out that carries out a subject request. Registered explicitly —
     * modular event discovery is off and must stay off
     * (.ai/rules/listeners.md).
     */
    protected function registerListeners(): void
    {
        Event::listen(DataExportRequested::class, [DispatchSubjectRequestFanOut::class, 'handleExport']);
        Event::listen(UserDeletionRequested::class, [DispatchSubjectRequestFanOut::class, 'handleDeletion']);
    }

    /**
     * Operator-console entries (ADR-0029). Viewing policies is its own
     * capability, separate from managing or publishing them: a legal reviewer
     * who may read every published text has no business publishing one. The
     * subject-request queue is a separate entry for the same reason — and
     * often a separate person.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'compliance',
                label: 'Policies',
                url: '/admin/policies',
                icon: 'shield',
                order: 30,
                permission: Permission::ViewPolicies->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'compliance',
                label: 'Data requests',
                url: '/admin/subject-requests',
                icon: 'file-search',
                order: 31,
                permission: Permission::ViewSubjectRequests->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }
}
