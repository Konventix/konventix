<?php

declare(strict_types=1);

namespace Konventix\Compliance\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Compliance\Console\ApplyRetentionRulesCommand;
use Konventix\Compliance\Console\SweepUnverifiedRequestsCommand;
use Konventix\Compliance\Console\WatchSubjectRequestDeadlinesCommand;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Events\DataExportCompleted;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Compliance\Events\SubjectRequestRefused;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Gdpr\ComplianceGdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Http\Middleware\EnsurePoliciesAccepted;
use Konventix\Compliance\Listeners\DispatchSubjectRequestFanOut;
use Konventix\Compliance\Listeners\NotifySubjectOfRequestOutcome;
use Konventix\Compliance\Listeners\PruneAuditTrail;
use Konventix\Compliance\Listeners\RecordAuditableEvents;
use Konventix\Compliance\Listeners\RecordCheckoutConsent;
use Konventix\Compliance\Listeners\StartRetentionClock;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Compliance\Support\PolicyMarkdown;
use Konventix\Event\Events\EventConcluded;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Slots\SlotRegistry;

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

        // Stateless: it holds two slugs and one statutory sentence, and
        // resolves everything else per call.
        $this->app->singleton(CheckoutLegalTexts::class);
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
        // Registered BEFORE the fan-out on purpose: a deletion acknowledgment
        // has to be queued while the recipient's address still exists — the
        // fan-out is what scrubs it, and under a synchronous queue it would
        // otherwise run first (see the listener's docblock).
        Event::listen(UserDeletionRequested::class, [NotifySubjectOfRequestOutcome::class, 'handleDeletionRequested']);
        Event::listen(DataExportRequested::class, [NotifySubjectOfRequestOutcome::class, 'handleExportRequested']);

        Event::listen(DataExportRequested::class, [DispatchSubjectRequestFanOut::class, 'handleExport']);
        Event::listen(UserDeletionRequested::class, [DispatchSubjectRequestFanOut::class, 'handleDeletion']);

        // The audit trail's subscriber half (Round 6 D-BATCH): one wildcard
        // listener that records events declaring themselves auditable. A list
        // of classes here would be a second place to keep in sync, maintained
        // by the module least likely to remember.
        Event::listen('*', [RecordAuditableEvents::class, 'handle']);

        // Retention: Event tells us when a clock starts, and Compliance
        // discharges its own rule through the same contract every other
        // module uses.
        Event::listen(EventConcluded::class, StartRetentionClock::class);
        Event::listen(RetentionPeriodElapsed::class, PruneAuditTrail::class);

        // A payer's declaration at a public checkout becomes Consent rows
        // here (GitLab #2). Commerce emits the event and never touches this
        // module's tables — events are the only contract between modules
        // (ADR-0004), and the architecture test allows nothing else.
        Event::listen(CheckoutConsentGiven::class, [RecordCheckoutConsent::class, 'handle']);

        // Telling the subject what happened to their request (Art. 12(3)).
        // Deletion is acknowledged when it *starts* — see the listener for
        // why a completion notice would have nowhere to go.
        Event::listen(DataExportCompleted::class, [NotifySubjectOfRequestOutcome::class, 'handleExportCompleted']);
        Event::listen(SubjectRequestRefused::class, [NotifySubjectOfRequestOutcome::class, 'handleRefused']);
    }

    /**
     * Subject-facing legal notices and the operator's deadline escalation.
     *
     * No topic (ADR-0041) on any of them, deliberately: the topic machinery
     * exists to honour preferences, and none of these is a notification a
     * person may switch off. Only the suppression list applies, which
     * SendMessage enforces regardless.
     */
    protected function registerMessageTemplates(): void
    {
        $registry = $this->app->make(TemplateRegistry::class);

        $registry->register(new MessageTemplate(
            key: NotifySubjectOfRequestOutcome::EXPORT_READY,
            subjects: [
                'de' => 'Ihre Datenkopie steht bereit',
                'en' => 'Your data export is ready',
            ],
            bodies: [
                // `@isset` rather than `@if`: `downloadUrl` is absent on the
                // sample payload and on an account-backed subject's mail, and
                // an unset variable inside `@if` throws (Blade::render() runs
                // this string standalone, with no view data() to default it).
                'de' => <<<'BLADE'
                    <p>Ihre Kopie der bei uns gespeicherten Daten ist fertig.</p>
                    @isset($downloadUrl)
                    <p>Sie können sie hier herunterladen: <a href="{{ $downloadUrl }}">Datenkopie herunterladen</a>.</p>
                    <p>Der Link funktioniert nur einmal — danach ist er ungültig, auch wenn er weitergeleitet wird.</p>
                    @else
                    <p>Sie finden sie unter „Einstellungen → Datenschutz“ zum Herunterladen.</p>
                    @endisset
                    <p>Verfügbar bis: {{ $availableUntil ?? 'siehe Konto' }}.</p>
                    <p>Aus Sicherheitsgründen verschicken wir die Datei nicht per E-Mail: der Download ist nur über den oben genannten Weg möglich.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Your copy of the data we hold about you is ready.</p>
                    @isset($downloadUrl)
                    <p>You can download it here: <a href="{{ $downloadUrl }}">Download your data</a>.</p>
                    <p>The link works once — after that it is spent, even if it was forwarded.</p>
                    @else
                    <p>You can download it under “Settings → Privacy”.</p>
                    @endisset
                    <p>Available until: {{ $availableUntil ?? 'see your account' }}.</p>
                    <p>We deliberately do not attach the file: the download is only available the way described above.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            // Legally significant: what we told a data subject, and when.
            logBodies: true,
            sample: ['requestType' => 'export', 'deadline' => '28.08.2026', 'availableUntil' => '11.09.2026'],
        ));

        $registry->register(new MessageTemplate(
            key: NotifySubjectOfRequestOutcome::DELETION_ACKNOWLEDGED,
            subjects: [
                'de' => 'Ihr Löschantrag ist eingegangen',
                'en' => 'We received your deletion request',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Wir haben Ihren Antrag auf Löschung erhalten und bearbeiten ihn bis spätestens {{ $deadline }}.</p>
                    <p>Ihre Daten werden anonymisiert: Unterlagen, die wir gesetzlich aufbewahren müssen — Rechnungen vor allem — bleiben bestehen, aber ohne Ihre persönlichen Angaben.</p>
                    <p>Dies ist die letzte Nachricht an diese Adresse: nach der Anonymisierung haben wir keine Kontaktdaten mehr von Ihnen.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>We received your deletion request and will complete it by {{ $deadline }} at the latest.</p>
                    <p>Your data will be anonymized: records we are legally required to keep — invoices above all — survive without your personal details attached.</p>
                    <p>This is the last message to this address: once anonymized, we no longer hold contact details for you.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            logBodies: true,
            sample: ['requestType' => 'deletion', 'deadline' => '28.08.2026'],
        ));

        $registry->register(new MessageTemplate(
            key: NotifySubjectOfRequestOutcome::REQUEST_REFUSED,
            subjects: [
                'de' => 'Zu Ihrem Antrag',
                'en' => 'About your request',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Wir können Ihrem Antrag ({{ $requestType }}) nicht entsprechen.</p>
                    <p>Rechtlicher Grund: {{ $reasonCode }}.</p>
                    <p>Sie können sich an die zuständige Datenschutzaufsichtsbehörde wenden.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>We are unable to act on your request ({{ $requestType }}).</p>
                    <p>Legal ground: {{ $reasonCode }}.</p>
                    <p>You may lodge a complaint with your data protection supervisory authority.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            logBodies: true,
            sample: ['requestType' => 'export', 'deadline' => '28.08.2026', 'reasonCode' => 'identity_not_verified'],
        ));

        $registry->register(new MessageTemplate(
            key: NotifySubjectOfRequestOutcome::REQUEST_VERIFICATION,
            subjects: [
                'de' => 'Bitte bestätigen Sie Ihre Anfrage',
                'en' => 'Please confirm your request',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Jemand — hoffentlich Sie — hat unter dieser Adresse eine {{ $requestType }}-Anfrage zu den bei uns gespeicherten Daten gestellt.</p>
                    <p>Um fortzufahren: <a href="{{ $verifyUrl }}">Anfrage bestätigen</a>.</p>
                    <p>Der Link ist {{ $expiresInHours }} Stunden gültig. Falls Sie diese Anfrage nicht gestellt haben, können Sie diese Nachricht ignorieren — es passiert nichts, ohne dass der Link geöffnet wird.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Someone — hopefully you — asked, at this address, for a {{ $requestType }} of the data we hold.</p>
                    <p>To proceed: <a href="{{ $verifyUrl }}">Confirm the request</a>.</p>
                    <p>The link is valid for {{ $expiresInHours }} hours. If you did not make this request, you can ignore this message — nothing happens unless the link is opened.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            // Legally significant: what we told a data subject, and when.
            logBodies: true,
            sample: ['requestType' => 'export', 'verifyUrl' => 'https://example.test/privacy/request/abc123', 'expiresInHours' => 24],
        ));

        $registry->register(new MessageTemplate(
            key: NotifySubjectOfRequestOutcome::REQUEST_ACKNOWLEDGED,
            subjects: [
                'de' => 'Ihre Anfrage ist eingegangen',
                'en' => 'We received your request',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Wir haben Ihre {{ $requestType }}-Anfrage erhalten und bearbeiten sie bis spätestens {{ $deadline }}.</p>
                    <p>Sie erhalten eine weitere Nachricht, sobald sie abgeschlossen ist.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>We received your {{ $requestType }} request and will complete it by {{ $deadline }} at the latest.</p>
                    <p>You will hear from us again once it is done.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            logBodies: true,
            sample: ['requestType' => 'export', 'deadline' => '28.08.2026'],
        ));

        $registry->register(new MessageTemplate(
            key: WatchSubjectRequestDeadlinesCommand::TEMPLATE_KEY,
            subjects: [
                'de' => 'Frist für einen Betroffenenantrag',
                'en' => 'Data subject request deadline',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Antrag {{ $requestId }} ({{ $requestType }}) — Frist {{ $deadline }}.</p>
                    <p>{{ $daysLeft < 0 ? 'Überfällig seit '.abs($daysLeft).' Tag(en).' : 'Noch '.$daysLeft.' Tag(e).' }}</p>
                    <p>{{ $openModules }} Modul(e) haben noch nicht geantwortet.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Request {{ $requestId }} ({{ $requestType }}) — deadline {{ $deadline }}.</p>
                    <p>{{ $daysLeft < 0 ? 'Overdue by '.abs($daysLeft).' day(s).' : $daysLeft.' day(s) left.' }}</p>
                    <p>{{ $openModules }} module(s) have not answered yet.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'requestId' => '01J8Z6QZ2R3S4T5U6V7W8X9Y0Z',
                'requestType' => 'export',
                'deadline' => '28.08.2026',
                'daysLeft' => 5,
                'openModules' => 2,
            ],
        ));
    }

    /**
     * The retention sweep, the deadline watch, and the unverified request sweep.
     * Registered explicitly rather than by discovery: a sweep that silently
     * failed to register would look like "nothing is out of retention" rather
     * than like a bug.
     */
    protected function registerSchedule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ApplyRetentionRulesCommand::class, SweepUnverifiedRequestsCommand::class, WatchSubjectRequestDeadlinesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(ApplyRetentionRulesCommand::class)->dailyAt('03:30');

            $schedule->command(SweepUnverifiedRequestsCommand::class)->hourly();

            // Early enough that a warning is in the operator's inbox at the
            // start of the working day the deadline actually matters on.
            $schedule->command(WatchSubjectRequestDeadlinesCommand::class)->dailyAt('06:45');
        });
    }

    /**
     * The consent control every public purchase path has to render before
     * its order button (GitLab #2).
     *
     * A UI slot rather than a prop the purchasing module computes: Commerce
     * may not read a policy row, and a legal control whose wording each
     * checkout writes for itself is a wording that drifts. The contract of
     * `checkout.consent` is that a contribution declares `required: true`
     * when the payer must confirm it before the order is placed — that is
     * all the rendering page needs to know about it.
     *
     * The closure is `static` deliberately — `SlotRegistry` is a singleton
     * that outlives the request under Octane, and a closure capturing `$this`
     * would pin this provider in memory with it.
     */
    protected function registerSlots(): void
    {
        $this->app->make(SlotRegistry::class)->add(
            slot: 'checkout.consent',
            module: 'compliance',
            component: 'compliance/CheckoutConsent',
            props: static function (): array {
                $brandId = app(BrandContext::class)->id();
                $texts = $brandId === null ? [] : app(CheckoutLegalTexts::class)->forBrand($brandId);

                return [
                    // False on an instance that has published neither text:
                    // the control renders nothing rather than asking someone
                    // to agree to documents that do not exist, and the
                    // checkout stays usable.
                    'required' => $texts !== [],
                    // Version ids stay on the server. The page needs a name
                    // and a link; which version a consent binds is resolved
                    // again when it is recorded, so a client cannot name one.
                    'policies' => array_map(static fn (array $text): array => [
                        'slug' => $text['slug'],
                        'name' => $text['name'],
                        'url' => $text['url'],
                    ], $texts),
                    'exemptionNotice' => CheckoutLegalTexts::EXEMPTION_NOTICE,
                ];
            },
            order: 10,
        );
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
            new NavigationItem(
                module: 'compliance',
                label: 'Audit trail',
                url: '/admin/audit',
                icon: 'scroll-text',
                order: 32,
                permission: Permission::ViewAuditLog->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'compliance',
                label: 'Retention',
                url: '/admin/retention',
                icon: 'timer',
                order: 33,
                permission: Permission::ManageRetentionRules->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * Compliance takes part in its own fan-out. Deletion is deliberately a
     * no-op: consent records prove consent was given, and destroying them to
     * honour a deletion request would destroy the proof it was honoured.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new ComplianceGdprHandler);
    }
}
