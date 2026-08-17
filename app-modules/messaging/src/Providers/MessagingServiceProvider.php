<?php

declare(strict_types=1);

namespace Konventix\Messaging\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Channels\ChannelMap;
use Konventix\Messaging\Channels\InAppChannel;
use Konventix\Messaging\Channels\MailChannel;
use Konventix\Messaging\Channels\PushChannel;
use Konventix\Messaging\Channels\WebPushSender;
use Konventix\Messaging\Console\FlushHeldMessagesCommand;
use Konventix\Messaging\Console\GenerateVapidKeysCommand;
use Konventix\Messaging\Contracts\MessageChannel;
use Konventix\Messaging\Contracts\PushSender;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Listeners\ProjectRecipient;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use RuntimeException;

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
        // channel is one register() line.
        $this->app->singleton(ChannelMap::class, function (): ChannelMap {
            $map = new ChannelMap;
            $map->register(Channel::Mail, MailChannel::class);
            $map->register(Channel::InApp, InAppChannel::class);
            $map->register(Channel::Push, PushChannel::class);

            return $map;
        });

        // Kept for anything resolving the bare contract; the pipeline
        // itself selects per envelope through ChannelMap.
        $this->app->bind(MessageChannel::class, MailChannel::class);

        // The web-push wire seam (ADR-0041 point 7): resolved only when a
        // push envelope actually delivers, so an unconfigured deployment
        // fails loudly at send time — never at boot.
        $this->app->bind(PushSender::class, function (): PushSender {
            $publicKey = config('services.webpush.public_key');
            $privateKey = config('services.webpush.private_key');

            if (! is_string($publicKey) || $publicKey === '' || ! is_string($privateKey) || $privateKey === '') {
                throw new RuntimeException(
                    'Web push is not configured: set VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY (generate with `php artisan messaging:vapid-keys`).',
                );
            }

            return new WebPushSender(
                publicKey: $publicKey,
                privateKey: $privateKey,
                subject: (string) config('services.webpush.subject'),
            );
        });
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

    /**
     * The one template Messaging itself owns: the digest that carries
     * several coalesced held messages (ADR-0041 point 5). Each item is a
     * held message's subject, rendered in the recipient's locale. German
     * default, matching the release's DACH framing (ADR-0030).
     */
    protected function registerMessageTemplates(): void
    {
        $this->app->make(TemplateRegistry::class)->register(new MessageTemplate(
            key: 'messaging.digest',
            subjects: [
                'de' => '{{ $count }} neue Benachrichtigungen',
                'en' => '{{ $count }} new notifications',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>während Sie nicht online waren, gab es {{ $count }} neue Benachrichtigungen:</p>
                    <ul>
                    @foreach ($items as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                    </ul>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>while you were away there were {{ $count }} new notifications:</p>
                    <ul>
                    @foreach ($items as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                    </ul>
                    BLADE,
            ],
            defaultLocale: 'de',
        ));
    }

    /**
     * The digest sweep (ADR-0041 point 5), every minute — hold windows are
     * minute-granular, and an idle sweep is one indexed query. Registered
     * explicitly: modular auto-discovers nothing under a module's
     * `src/Console`, and a sweep that silently failed to register would
     * look like "nothing is held" rather than like a bug.
     */
    protected function registerSchedule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([FlushHeldMessagesCommand::class, GenerateVapidKeysCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(FlushHeldMessagesCommand::class)->everyMinute();
        });
    }
}
