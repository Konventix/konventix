<?php

declare(strict_types=1);

namespace Konventix\Messaging\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\DunningLevelRaised;
use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Content\Events\AnnouncementPublished;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Branding\BrandMailIdentityResolver;
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
use Konventix\Messaging\Enums\Permission;
use Konventix\Messaging\Gdpr\MessagingGdprHandler;
use Konventix\Messaging\Listeners\ProjectRecipient;
use Konventix\Messaging\Listeners\PruneDeliveryLog;
use Konventix\Messaging\Listeners\SendAnnouncementNotifications;
use Konventix\Messaging\Listeners\SendDunningNotice;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
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

        // Never a singleton holding a brand (ADR-0010, Octane): a worker
        // sends mail for many brands over its lifetime, so this is scoped —
        // forgotten between requests/jobs, resolved fresh for the next one.
        $this->app->scoped(BrandMailIdentityResolver::class);

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
     * The operator console's mail-settings screen (ADR-0028): sender
     * identity and transport are Messaging-owned config data, so the nav
     * entry is Messaging's to contribute (ADR-0003).
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'messaging',
                label: 'Mail settings',
                url: '/admin/mail-settings',
                icon: 'mail',
                order: 40,
                permission: Permission::ManageSenderIdentity->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * The recipient read model projects from Identity's account events
     * (SPEC.md "Consumes", ADR-0004) — plus the two intent events
     * (ADR-0041 point 8): `AnnouncementPublished` and `DunningLevelRaised`
     * deliberately address Messaging without naming a template, so for
     * exactly these the listener lives here. Everything else keeps the
     * rule: a module that wants a message sends one.
     */
    protected function registerListeners(): void
    {
        Event::listen(UserRegistered::class, ProjectRecipient::class);
        Event::listen(UserProfileUpdated::class, ProjectRecipient::class);

        Event::listen(AnnouncementPublished::class, SendAnnouncementNotifications::class);
        Event::listen(DunningLevelRaised::class, SendDunningNotice::class);

        // Compliance publishes the delivery log's retention period
        // (D-CPL-01); Messaging is the module that deletes its own rows.
        Event::listen(RetentionPeriodElapsed::class, PruneDeliveryLog::class);
    }

    /**
     * The topics behind the intent events (ADR-0041 point 8): Messaging
     * owns their interpretation, so it also declares their policy.
     * Announcements are preference-gated and digestable; a payment
     * reminder offers no opt-out — only the mail suppression list applies.
     */
    protected function registerMessageTopics(): void
    {
        $topics = $this->app->make(TopicRegistry::class);

        $topics->register(new Topic(
            key: 'content.announcements',
            label: 'Announcements',
            defaultChannels: [Channel::InApp, Channel::Push, Channel::Mail],
            suppressible: true,
            digestable: true,
        ));

        $topics->register(new Topic(
            key: 'commerce.dunning',
            label: 'Payment reminders',
            defaultChannels: [Channel::Mail],
            suppressible: false,
        ));
    }

    /**
     * The templates Messaging itself owns: the digest that carries several
     * coalesced held messages (ADR-0041 point 5), and the wording behind
     * the two intent events (point 8) — announcement and dunning notice.
     * German defaults, matching the release's DACH framing (ADR-0030).
     */
    protected function registerMessageTemplates(): void
    {
        $registry = $this->app->make(TemplateRegistry::class);

        $registry->register(new MessageTemplate(
            key: 'messaging.announcement',
            subjects: [
                'de' => '{{ $title }}',
                'en' => '{{ $title }}',
            ],
            bodies: [
                'de' => '<p>Neue Ankündigung: <strong>{{ $title }}</strong></p>',
                'en' => '<p>New announcement: <strong>{{ $title }}</strong></p>',
            ],
            defaultLocale: 'de',
        ));

        $registry->register(new MessageTemplate(
            key: 'messaging.dunning-notice',
            subjects: [
                'de' => 'Zahlungserinnerung {{ $level }} zur Rechnung {{ $invoiceNumber }}',
                'en' => 'Payment reminder {{ $level }} for invoice {{ $invoiceNumber }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag {{ $recipientName }},</p>
                    <p>die Rechnung <strong>{{ $invoiceNumber }}</strong> über
                    <strong>{{ $totalGross }} {{ $currency }}</strong> war am
                    <strong>{{ $dueOn }}</strong> fällig und ist seit
                    {{ $daysOverdue }} Tagen offen.</p>
                    <p>Bitte begleichen Sie den Betrag unter Angabe der
                    Rechnungsnummer. Sollte die Zahlung bereits unterwegs sein,
                    betrachten Sie diese Erinnerung als gegenstandslos.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Dear {{ $recipientName }},</p>
                    <p>invoice <strong>{{ $invoiceNumber }}</strong> for
                    <strong>{{ $totalGross }} {{ $currency }}</strong> was due on
                    <strong>{{ $dueOn }}</strong> and has been outstanding for
                    {{ $daysOverdue }} days.</p>
                    <p>Please settle the amount quoting the invoice number. If
                    your payment is already on its way, kindly disregard this
                    reminder.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
        ));

        $registry->register(new MessageTemplate(
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

    /**
     * Everything here is a way to reach a person, so deletion is unusually
     * thorough — except suppressions, which exist to keep NOT sending.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new MessagingGdprHandler);
    }
}
