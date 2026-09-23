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
use Konventix\Messaging\Events\MessageDelivered;
use Konventix\Messaging\Events\MessageFailed;
use Konventix\Messaging\Events\MessageSuppressed;
use Konventix\Messaging\Gdpr\MessagingGdprHandler;
use Konventix\Messaging\Listeners\Metrics\RecordMessageMetrics;
use Konventix\Messaging\Listeners\ProjectRecipient;
use Konventix\Messaging\Listeners\PruneDeliveryLog;
use Konventix\Messaging\Listeners\SendAnnouncementNotifications;
use Konventix\Messaging\Listeners\SendDunningNotice;
use Konventix\Messaging\Metrics\MessagingGauges;
use Konventix\Messaging\Metrics\MessagingMetrics;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use RuntimeException;
use Spatie\Prometheus\Facades\Prometheus;

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

    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();
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
        $registry = $this->app->make(NavigationRegistry::class);

        $registry->add(
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

        // ADR-0048 decision 6: the delivery log, gated on merely seeing
        // that a message was sent — reading its content and resending it
        // are separate capabilities the screen itself checks per action.
        $registry->add(
            new NavigationItem(
                module: 'messaging',
                label: 'Messages',
                url: '/admin/messages',
                icon: 'send',
                order: 41,
                permission: Permission::ViewMessageLog->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );

        // ADR-0048 decisions 4 and 6 (Task 8): the template editor —
        // rewriting a mail per brand is its own capability, separate from
        // merely seeing what was sent.
        $registry->add(
            new NavigationItem(
                module: 'messaging',
                label: 'Message templates',
                url: '/admin/messages/templates',
                icon: 'layout-template',
                order: 42,
                permission: Permission::ManageMessageTemplates->value,
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

        // ADR-0059: Messaging's own metrics, from its own events.
        Event::listen([MessageDelivered::class, MessageFailed::class, MessageSuppressed::class], RecordMessageMetrics::class);
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
     * English is the default locale (ADR-0068); the German maps address
     * the reader as du.
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
                'de' => <<<'BLADE'
                    <p>Neue Ankündigung: <strong>{{ $title }}</strong></p>
                    @if ($url)
                    <p><a href="{{ $url }}">Zum Beitrag</a></p>
                    @endif
                    BLADE,
                'en' => <<<'BLADE'
                    <p>New announcement: <strong>{{ $title }}</strong></p>
                    @if ($url)
                    <p><a href="{{ $url }}">Read the article</a></p>
                    @endif
                    BLADE,
            ],
            defaultLocale: 'en',
            sample: [
                'title' => 'Neue Programmpunkte veröffentlicht',
                'url' => 'https://sxlan.example/news/neue-programmpunkte',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'messaging.dunning-notice',
            subjects: [
                'de' => 'Zahlungserinnerung {{ $level }} zur Rechnung {{ $invoiceNumber }}',
                'en' => 'Payment reminder {{ $level }} for invoice {{ $invoiceNumber }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Hallo {{ $recipientName }},</p>
                    <p>die Rechnung <strong>{{ $invoiceNumber }}</strong> über
                    <strong>{{ $totalGross }} {{ $currency }}</strong> war am
                    <strong>{{ $dueOn }}</strong> fällig und ist seit
                    {{ $daysOverdue }} Tagen offen.</p>
                    <p>Bitte begleiche den Betrag unter Angabe der
                    Rechnungsnummer. Sollte deine Zahlung bereits unterwegs sein,
                    betrachte diese Erinnerung als gegenstandslos.</p>
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
            defaultLocale: 'en',
            sample: [
                'recipientName' => 'Erika Musterfrau',
                'invoiceNumber' => 'RE-2026-0142',
                'level' => 2,
                'daysOverdue' => 14,
                'totalGross' => '35.70',
                'currency' => 'EUR',
                'dueOn' => '28.08.2026',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'messaging.digest',
            subjects: [
                'de' => '{{ $count }} neue Benachrichtigungen',
                'en' => '{{ $count }} new notifications',
            ],
            bodies: [
                // Round 4 Task 27: an item is `{subject, url}`, so a digested
                // message keeps its own link. `@if` over the item's `url` is
                // inside `TemplateOverrideGuard`'s allow-list (a payload
                // field with index access), which is what lets an operator
                // round-trip this default through the template editor.
                'de' => <<<'BLADE'
                    <p>Hallo,</p>
                    <p>während du nicht online warst, gab es {{ $count }} neue Benachrichtigungen:</p>
                    <ul>
                    @foreach ($items as $item)
                        <li>
                        @if ($item['url'])
                        <a href="{{ $item['url'] }}">{{ $item['subject'] }}</a>
                        @else
                        {{ $item['subject'] }}
                        @endif
                        </li>
                    @endforeach
                    </ul>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>while you were away there were {{ $count }} new notifications:</p>
                    <ul>
                    @foreach ($items as $item)
                        <li>
                        @if ($item['url'])
                        <a href="{{ $item['url'] }}">{{ $item['subject'] }}</a>
                        @else
                        {{ $item['subject'] }}
                        @endif
                        </li>
                    @endforeach
                    </ul>
                    BLADE,
            ],
            defaultLocale: 'en',
            sample: [
                'count' => 3,
                'items' => [
                    ['subject' => 'Neue Ankündigung: Programm steht', 'url' => 'https://sxlan.example/news/programm-steht'],
                    ['subject' => 'Zahlungserinnerung RE-2026-0142', 'url' => null],
                    ['subject' => 'Ihre Anmeldung ist bestätigt', 'url' => null],
                ],
            ],
        ));

        // Task 8 (ADR-0048 decisions 4/6): what "Send test mail to me"
        // actually sends — an operator confirming a brand's sender identity
        // and layout render, not a template any domain event triggers.
        $registry->register(new MessageTemplate(
            key: 'messaging.test-mail',
            subjects: [
                'de' => 'Testnachricht von {{ $brandName }}',
                'en' => 'Test message from {{ $brandName }}',
            ],
            bodies: [
                'de' => '<p>Dies ist eine Testnachricht von {{ $brandName }}, gesendet am {{ $date }}.</p>',
                'en' => '<p>This is a test message from {{ $brandName }}, sent on {{ $date }}.</p>',
            ],
            defaultLocale: 'en',
            sample: ['brandName' => 'SXLan', 'date' => '28.08.2026'],
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
     * Messaging's metrics (ADR-0059): catalogue entries here, listeners
     * below.
     */
    /**
     * ADR-0059: counters in the catalogue, the mail-quota gauges answered
     * by `MessagingGauges` at scrape time.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...MessagingMetrics::definitions());

        Prometheus::registerCollectorClasses([MessagingGauges::class]);
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
