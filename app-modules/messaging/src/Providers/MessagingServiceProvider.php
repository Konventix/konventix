<?php

declare(strict_types=1);

namespace Konventix\Messaging\Providers;

use Illuminate\Support\Facades\Event;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Channels\ChannelMap;
use Konventix\Messaging\Channels\MailChannel;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Listeners\ProjectRecipient;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\TopicRegistry;
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
        $this->app->singleton(TopicRegistry::class);

        // Channel drivers resolve through the map (ADR-0041): adding a
        // channel is one register() line. In-app and push register here as
        // their slices land.
        $this->app->singleton(ChannelMap::class, function (): ChannelMap {
            $map = new ChannelMap;
            $map->register(Channel::Mail, MailChannel::class);

            return $map;
        });

        // Kept for anything resolving the bare contract; the pipeline
        // itself selects per envelope through ChannelMap.
        $this->app->bind(MessageChannel::class, MailChannel::class);
    }

    /**
     * The recipient read model projects from Identity's account events
     * (SPEC.md "Consumes", ADR-0004).
     */
    protected function registerListeners(): void
    {
        Event::listen(UserRegistered::class, ProjectRecipient::class);
        Event::listen(UserProfileUpdated::class, ProjectRecipient::class);
    }
}
