<?php

declare(strict_types=1);

namespace Konventix\Messaging\Providers;

use Konventix\Messaging\Channels\MailChannel;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;

/**
 * Registers everything this module owns. Registration is unconditional and
 * happens at boot; whether the module is *active* is a per-brand Pennant gate
 * applied at four points — routes, navigation, UI slots, listeners (ADR-0003).
 *
 * Never move any of this wiring into a central provider.
 */
class MessagingServiceProvider extends ModuleServiceProvider
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
        return 'messaging';
    }

    protected function registerBindings(): void
    {
        // Boot-time registrations only, safe for a long-lived worker to share
        // across requests — the same reasoning Platform applies to its own
        // registries (NavigationRegistry, SlotRegistry).
        $this->app->singleton(TemplateRegistry::class);

        // Mail is the only channel today (TODO.md Next item 1); channel
        // selection between drivers is Backlog.
        $this->app->bind(MessageChannel::class, MailChannel::class);
    }
}
