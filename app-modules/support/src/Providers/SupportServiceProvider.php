<?php

declare(strict_types=1);

namespace Konventix\Support\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Messaging\Enums\Channel;
use Konventix\Messaging\Enums\TopicAudience;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Messaging\Topics\Topic;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Hub\HubLink;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Support\Captcha\CaptchaVerifier;
use Konventix\Support\Captcha\NullCaptchaVerifier;
use Konventix\Support\Console\PurgeSupportRequestsCommand;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Events\SupportRequestMessagePosted;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Gdpr\SupportGdprHandler;
use Konventix\Support\Http\Middleware\RenderExpiredSupportLink;
use Konventix\Support\Listeners\AcknowledgeReporterOnRequestOpened;
use Konventix\Support\Listeners\Metrics\RecordSupportMetrics;
use Konventix\Support\Listeners\NotifyOperatorsOnReporterReply;
use Konventix\Support\Listeners\NotifyOperatorsOnRequestOpened;
use Konventix\Support\Listeners\NotifyReporterOnReply;
use Konventix\Support\Listeners\NotifyReporterOnStateChanged;
use Konventix\Support\Metrics\SupportGauges;
use Konventix\Support\Metrics\SupportMetrics;
use Konventix\Support\Models\SupportRequest;
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

    /**
     * `parent::boot()` first — it loads the two route files that name
     * `throttle:support-report`, and the limiter itself is resolved per
     * request rather than at registration (Commerce's webhook throttle is
     * the same shape).
     */
    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();
        $this->configureRateLimiting();

        // The guest link's failure mode as an alias, registered here
        // because a module owns its own middleware and wires nothing
        // centrally (ADR-0003) — the same shape Compliance's
        // `require-consent` uses. It is applied *outside* `signed` in
        // `routes/web/support.php`, which is what lets it turn the
        // signature failure into the re-issue page.
        $this->app->make(Router::class)
            ->aliasMiddleware('support-link-expired', RenderExpiredSupportLink::class);
    }

    /**
     * The report endpoint's abuse seam (D-SUP-05): the `CaptchaVerifier`
     * exists from day one so that turning a challenge on later is a config
     * value and a new class, never a change to the controller.
     *
     * `config('support.captcha.driver')` is null and has no other value
     * today — a named driver is refused loudly rather than silently falling
     * through to "no captcha", because a deployment that thinks it has one
     * and does not is the worst of the three states.
     */
    protected function registerBindings(): void
    {
        $this->app->bind(CaptchaVerifier::class, function (): CaptchaVerifier {
            $driver = config('support.captcha.driver');

            return match ($driver) {
                null, '' => new NullCaptchaVerifier,
                default => throw new InvalidArgumentException(
                    "Unknown support captcha driver [{$driver}]. Implement CaptchaVerifier and bind it here first."
                ),
            };
        });
    }

    /**
     * The per-account / per-origin half of §6.4. Five reports per ten
     * minutes is deliberately loose: a person whose checkout is broken will
     * legitimately send two or three in a row as they learn more, and the
     * limit is a ceiling on abuse rather than a shaper of real traffic.
     * The second axis — three an hour per e-mail address — lives in the
     * controller, because only the controller has read the address, and it
     * is the one a pool of source addresses cannot spend around.
     *
     * Keyed on the account when there is one, so a household or a
     * conference hall behind one NAT address does not share a budget.
     * Nothing stores the address of origin (design §11): it is hashed into
     * a limiter key and forgotten.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('support-report', fn (Request $request): Limit => Limit::perMinutes(10, 5)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown')));

        // The re-issue budget (design §8.2): three fresh links an hour per
        // reference. Keyed on the **reference** and not on the caller,
        // because the thing being protected is somebody else's inbox — an
        // address a stranger holding an eight-character reference could
        // otherwise have a link mailed to as often as they liked, from as
        // many source addresses as they liked. The cost of that choice is
        // that a person can lock their own re-issue out for an hour by
        // pressing the button four times, which is an hour's wait against
        // a mail flood.
        //
        // Normalised the same way `ReissueSupportLink` normalises before
        // looking a reference up, so `a1b2c3d4` and `A1B2-C3D4` cannot buy
        // three attempts each.
        RateLimiter::for('support-link', fn (Request $request): Limit => Limit::perHour(3)
            ->by('support-link:'.sha1(Str::lower(
                preg_replace('/[^0-9A-Za-z]/', '', (string) $request->input('reference')) ?? ''
            ))));
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
     * The console's one Support entry (design §9.3), with the open count as
     * a badge (D-SUP-13).
     *
     * The badge is a **closure**, and that is the whole of the Platform
     * change this slice needed. Navigation is registered once at boot
     * (ADR-0003) while a count is a fact about one request, so a number here
     * would be the number the worker happened to see when it started and
     * would never move again — the same trap `.ai/rules/providers.md`
     * describes for a scoped binding captured in a singleton's constructor.
     * `NavigationItem::toArray()` calls it when the entry is serialised for
     * a request, which is the only moment the count is both wanted and
     * cheap, and the closure resolves everything it needs then.
     *
     * The count is **brand-wide**: every open report of every brand, plus
     * the brandless ones filed on the console. That is what the inbox
     * defaults to showing, so the badge and the first screen agree — and it
     * is possible at all only because `SupportRequest` carries no
     * `BelongsToBrand`, which on the admin host would harden this query to
     * zero (ADR-0029).
     *
     * `order: 40` puts it after Compliance's block, at the end of the
     * operator's own tools; the entry carries no feature, because Support
     * cannot be switched off (ADR-0063).
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'support',
                label: 'Support requests',
                url: '/admin/support-requests',
                icon: 'life-buoy',
                order: 40,
                permission: Permission::ViewRequests->value,
                area: NavigationItem::AREA_ADMIN,
                badge: fn (): int => SupportRequest::query()->open()->count(),
            ),
        );

        // The reporter's own entry in the account menu (design §8.1,
        // ADR-0044). No `permission`, and none is missing: the menu renders
        // only for a signed-in account and `MySupportRequestsController`
        // authorizes on ownership, which is not a capability anybody can be
        // granted — the same reasoning Commerce's "My orders" and
        // Admission's "My tickets" already carry.
        //
        // No `badge` either, deliberately. The console's count is a work
        // queue; a person's own reports are not, and a number beside "My
        // support requests" would be a nudge to check something nobody is
        // waiting on them for. It is also a query per account menu render,
        // on every brand page, for a list that is usually empty.
        //
        // `order: 30` puts it after the things a person came to the brand
        // for — their registrations, their tickets, their orders — because
        // this is where they go when one of those went wrong.
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'support',
                label: 'My support requests',
                url: '/my/support-requests',
                icon: 'life-buoy',
                order: 30,
                area: NavigationItem::AREA_ACCOUNT,
            ),
        );
    }

    /**
     * "Hilfe" on the live hub (D-HUB-05): the one thing a participant
     * needs when everything else on the page is wrong.
     *
     * A `component` instead of a `url`, and the only one of the four launch
     * contributions that needs it: the report dialog is already on the
     * page, so the link opens it rather than navigating away from the event
     * the person is standing at. `HelpLink.vue` calls the module's own
     * `useSupportDialog()` — the same shared state the topbar launcher
     * opens, so the two can never disagree about whether it is open.
     *
     * `order: 90`, last: help is where you go when the rest did not
     * answer. No `permission` — reporting is open to anybody who reached
     * the hub, and Support is kernel-style (ADR-0063), so the module gate
     * `HubRegistry` applies passes for every brand.
     */
    protected function registerHub(): void
    {
        $this->app->make(HubRegistry::class)->add(new HubLink(
            module: 'support',
            key: 'support.help',
            label: 'Hilfe',
            component: 'support/hub/HelpLink',
            order: 90,
        ));
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

        // Design §7: the two audiences of one report. Registered
        // explicitly, because modular's event discovery is off and a
        // listener without an `Event::listen` line never fires
        // (`.ai/rules/listeners.md`).
        Event::listen(SupportRequestOpened::class, NotifyOperatorsOnRequestOpened::class);
        Event::listen(SupportRequestOpened::class, AcknowledgeReporterOnRequestOpened::class);

        // Design §7, S3: what happens to a report *after* it was filed.
        // Both of the message listeners hang off the same event and split
        // on who wrote the entry, because a thread has two directions and
        // exactly one of them is news to each side — an operator's reply is
        // news to the reporter, the reporter's reply is news to the
        // operators, and neither is news to the person who just typed it.
        //
        // Each is registered explicitly: modular's event discovery is off,
        // and a listener without an `Event::listen` line never fires
        // (`.ai/rules/listeners.md`).
        Event::listen(SupportRequestStateChanged::class, NotifyReporterOnStateChanged::class);
        Event::listen(SupportRequestMessagePosted::class, NotifyReporterOnReply::class);
        Event::listen(SupportRequestMessagePosted::class, NotifyOperatorsOnReporterReply::class);
    }

    /**
     * Design §7. Two topics, two audiences, and the split matters:
     *
     * `support.requests` is an **operator** topic (the `TopicAudience`
     * split of the 2026-09-21 bugfix round), so its in-app rows show in the
     * console bell and never in the participant inbox of an organizer who
     * is also a registrant — and the preferences page offers it only to
     * accounts that can reach the console. It is digestable for later,
     * calmer sends on it; every send today is `urgent` and bypasses the
     * window, because a new report is worth an immediate nudge.
     *
     * `support.request-updates` is the reporter's own, and is not
     * digestable: three separate things happening to somebody's report is
     * three things they want to know about, not one summary.
     *
     * D-SUP-06, once more because it is the decision most easily lost: a
     * "purchase affected" report reaches the on-call channel as a vmalert
     * rule on `support_requests_total`, and reaches operators on *this*
     * topic key. Never on Commerce's.
     */
    protected function registerMessageTopics(): void
    {
        $registry = $this->app->make(TopicRegistry::class);

        $registry->register(new Topic(
            key: 'support.requests',
            label: 'Support requests',
            defaultChannels: [Channel::InApp, Channel::Mail],
            suppressible: true,
            digestable: true,
            audience: TopicAudience::Operator,
        ));

        $registry->register(new Topic(
            key: 'support.request-updates',
            label: 'Updates on my support requests',
            defaultChannels: [Channel::InApp, Channel::Mail],
            suppressible: true,
            digestable: false,
            audience: TopicAudience::Participant,
        ));
    }

    /**
     * The two templates S1 ships (design §7). Both are **branch-free**:
     * every sentence that differs between a guest and an account, or
     * between one report and the next, is composed by the sending listener
     * and echoed here as a prepared field.
     *
     * That is not stylistic. A brand operator may override any template
     * through the console editor, `TemplateOverrideGuard` allows an
     * override no expression at all, and `templates/Edit.vue` seeds the
     * editor with *this* default — so a default that branched would be one
     * an operator cannot save back (`TemplateRegistryTest` fails the build
     * for it). `SendClaimLink`'s prepared `managerHandle` is the same
     * pattern.
     *
     * `de` first and default: the deployment's own audience is German,
     * while the UI copy is English until the platform decides on i18n
     * (D-SUP-09).
     */
    protected function registerMessageTemplates(): void
    {
        $registry = $this->app->make(TemplateRegistry::class);

        $registry->register(new MessageTemplate(
            key: 'support.request-received',
            subjects: [
                'de' => 'Ihre Meldung {{ $reference }} ist bei uns eingegangen',
                'en' => 'We received your report {{ $reference }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>vielen Dank für Ihre Rückmeldung. Wir haben Ihre Meldung unter der
                    Nummer <strong>{{ $reference }}</strong> aufgenommen
                    ({{ $categories }}).</p>
                    <p>{{ $accessNoteDe }}</p>
                    <p><a href="{{ $requestUrl }}">Meldung ansehen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>thank you for telling us. Your report has been filed under the
                    reference <strong>{{ $reference }}</strong> ({{ $categories }}).</p>
                    <p>{{ $accessNoteEn }}</p>
                    <p><a href="{{ $requestUrl }}">View your report</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'reference' => 'A1B2C3D4',
                'categories' => 'Purchase affected, Something does not work',
                'requestUrl' => 'https://example.test/support/requests/01K5MZ6V4N8YQ2W3J7R9T1X0AB?signature=abc',
                'accessNoteDe' => 'Über den Link unten können Sie Ihre Meldung 30 Tage lang ansehen und beantworten.',
                'accessNoteEn' => 'The link below lets you read and answer your report for the next 30 days.',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'support.request-notice',
            subjects: [
                'de' => '{{ $title }}',
                'en' => '{{ $title }}',
            ],
            bodies: [
                'de' => '<p>{{ $detail }}</p><p><a href="{{ $url }}">Im Konsolenbereich öffnen</a></p>',
                'en' => '<p>{{ $detail }}</p><p><a href="{{ $url }}">Open in the console</a></p>',
            ],
            defaultLocale: 'de',
            sample: [
                'reference' => 'A1B2C3D4',
                'title' => 'New support request A1B2C3D4 · Purchase affected',
                'detail' => 'The pay button does nothing on my phone. — Brand: sxlan. Route: commerce.pay.show. Release: 0.8.0.',
                'url' => 'https://admin.example.test/admin/support-requests/01K5MZ6V4N8YQ2W3J7R9T1X0AB',
            ],
        ));

        // The three S3 templates (design §7, §8.2). All branch-free for
        // the reason stated above, which here costs a prepared field per
        // locale where an `@if` over five states would have been shorter
        // and unsaveable through the override editor.
        //
        // The state name and the sentence that says what it means are two
        // fields rather than one: "Resolved" and "we are going to leave
        // this as it is" are the two halves a person actually reads, and a
        // mail that named only the state would leave them guessing which
        // of the two terminal answers they had just been given.
        $registry->register(new MessageTemplate(
            key: 'support.request-state-changed',
            subjects: [
                'de' => 'Ihre Meldung {{ $reference }}: {{ $stateDe }}',
                'en' => 'Your report {{ $reference }}: {{ $stateEn }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>der Stand Ihrer Meldung <strong>{{ $reference }}</strong> hat sich
                    geändert: <strong>{{ $stateDe }}</strong>.</p>
                    <p>{{ $noteDe }}</p>
                    <p><a href="{{ $requestUrl }}">Meldung ansehen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>there is news about your report <strong>{{ $reference }}</strong>:
                    <strong>{{ $stateEn }}</strong>.</p>
                    <p>{{ $noteEn }}</p>
                    <p><a href="{{ $requestUrl }}">View your report</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'reference' => 'A1B2C3D4',
                'stateDe' => 'In Bearbeitung',
                'stateEn' => 'In progress',
                'noteDe' => 'Jemand kümmert sich jetzt darum.',
                'noteEn' => 'Somebody is working on it now.',
                'requestUrl' => 'https://example.test/my/support-requests/01K5MZ6V4N8YQ2W3J7R9T1X0AB',
            ],
        ));

        // The excerpt and not the whole reply, on purpose: a mail carrying
        // the full answer becomes the place people read it, and the thread
        // — where the next question can be asked — becomes the place
        // nobody goes.
        $registry->register(new MessageTemplate(
            key: 'support.request-replied',
            subjects: [
                'de' => 'Neue Antwort zu Ihrer Meldung {{ $reference }}',
                'en' => 'A reply on your report {{ $reference }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>zu Ihrer Meldung <strong>{{ $reference }}</strong> gibt es eine
                    Antwort:</p>
                    <blockquote>{{ $excerpt }}</blockquote>
                    <p><a href="{{ $requestUrl }}">Antwort lesen und antworten</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>somebody has answered your report <strong>{{ $reference }}</strong>:</p>
                    <blockquote>{{ $excerpt }}</blockquote>
                    <p><a href="{{ $requestUrl }}">Read it and write back</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'reference' => 'A1B2C3D4',
                'excerpt' => 'Thank you — we found the cause and a fix is going out this evening.',
                'requestUrl' => 'https://example.test/my/support-requests/01K5MZ6V4N8YQ2W3J7R9T1X0AB',
            ],
        ));

        // The self-service re-issue (design §8.2). It says nothing about
        // *why* a new link was asked for and carries no reason field: the
        // person who pressed the button knows, and the person who did not
        // press it — if somebody else typed their reference — should learn
        // as little as possible from the mail they did not expect.
        $registry->register(new MessageTemplate(
            key: 'support.request-link',
            subjects: [
                'de' => 'Ihr neuer Zugang zu Meldung {{ $reference }}',
                'en' => 'Your new link to report {{ $reference }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Guten Tag,</p>
                    <p>hier ist ein neuer Zugang zu Ihrer Meldung
                    <strong>{{ $reference }}</strong>. Der Link gilt 30 Tage.</p>
                    <p><a href="{{ $requestUrl }}">Meldung ansehen</a></p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>here is a new link to your report <strong>{{ $reference }}</strong>.
                    It works for the next 30 days.</p>
                    <p><a href="{{ $requestUrl }}">View your report</a></p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'reference' => 'A1B2C3D4',
                'requestUrl' => 'https://example.test/support/requests/01K5MZ6V4N8YQ2W3J7R9T1X0AB?signature=abc',
            ],
        ));
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
