<?php

declare(strict_types=1);

namespace Konventix\Compliance\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Konventix\Commerce\Events\CheckoutConsentGiven;
use Konventix\Compliance\Bindings\BindingContext;
use Konventix\Compliance\Bindings\PolicyResolver;
use Konventix\Compliance\Console\ApplyRetentionRulesCommand;
use Konventix\Compliance\Console\SweepUnverifiedRequestsCommand;
use Konventix\Compliance\Console\WatchSubjectRequestDeadlinesCommand;
use Konventix\Compliance\Data\CheckoutLegalTextGapData;
use Konventix\Compliance\Data\CheckoutLegalTextRoleData;
use Konventix\Compliance\Enums\Permission;
use Konventix\Compliance\Events\ConsentGranted;
use Konventix\Compliance\Events\ConsentPromptRaised;
use Konventix\Compliance\Events\ConsentWithdrawn;
use Konventix\Compliance\Events\DataExportCompleted;
use Konventix\Compliance\Events\DataExportRequested;
use Konventix\Compliance\Events\RetentionPeriodElapsed;
use Konventix\Compliance\Events\SubjectRequestRefused;
use Konventix\Compliance\Events\UserDeletionRequested;
use Konventix\Compliance\Gdpr\ComplianceGdprHandler;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Compliance\Http\Middleware\EnsurePoliciesAccepted;
use Konventix\Compliance\Legal\PolicyPublicLegalTexts;
use Konventix\Compliance\Listeners\DispatchSubjectRequestFanOut;
use Konventix\Compliance\Listeners\FlagOutstandingContractConsent;
use Konventix\Compliance\Listeners\Metrics\RecordConsentMetrics;
use Konventix\Compliance\Listeners\NotifySubjectOfRequestOutcome;
use Konventix\Compliance\Listeners\PruneAuditTrail;
use Konventix\Compliance\Listeners\RecordAuditableEvents;
use Konventix\Compliance\Listeners\RecordCheckoutConsent;
use Konventix\Compliance\Listeners\RecordConsentDeclaration;
use Konventix\Compliance\Listeners\StartRetentionClock;
use Konventix\Compliance\Metrics\ComplianceGauges;
use Konventix\Compliance\Metrics\ComplianceMetrics;
use Konventix\Compliance\Support\CheckoutLegalTexts;
use Konventix\Compliance\Support\ConsentContribution;
use Konventix\Compliance\Support\OutstandingConsents;
use Konventix\Compliance\Support\PolicyMarkdown;
use Konventix\Event\Events\EventConcluded;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Legal\PublicLegalTexts;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Slots\SlotRegistry;
use Konventix\Registration\Events\RegistrationConsentGiven;
use Spatie\Prometheus\Facades\Prometheus;

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

        // ADR-0066: the same question with a context (spec §5.4). Stateless
        // too — nothing is cached, because a rebinding has to take effect on
        // the next request. `CheckoutLegalTexts` stays the no-context call
        // that `missingSlugsForBrands()` and the #222 gap notice ask.
        $this->app->singleton(PolicyResolver::class);

        // Platform declares the contract, Compliance answers it (#184,
        // ADR-0004): the kernel may not import a `Policy`, so this is how
        // code outside this module links to a published legal text. Replaces
        // Platform's null default.
        $this->app->singleton(PublicLegalTexts::class, PolicyPublicLegalTexts::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerMetrics();

        // The consent gate as an opt-in alias other modules apply to their
        // own routes (see EnsurePoliciesAccepted). Compliance registers the
        // alias and wires nothing centrally — ADR-0003.
        $this->app->make(Router::class)
            ->aliasMiddleware('require-consent', EnsurePoliciesAccepted::class);
    }

    /**
     * Compliance's metrics (ADR-0059, ADR-0066 spec §10): catalogue entries
     * here, the consent recorders in `Listeners\Metrics`, the outstanding-
     * contract gauge in `ComplianceGauges`, and
     * `policy_binding_matches_total` written by the resolver itself, which
     * is the only thing that knows a binding matched.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...ComplianceMetrics::definitions());

        Prometheus::registerCollectorClasses([ComplianceGauges::class]);
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

        // GitLab #205: the login gate's question is Compliance's, and the
        // answer crosses to Identity as one session key and nothing else
        // (ADR-0004) — see the listener. Laravel's own `Login` event is the
        // one moment the password, two-factor and social ways in all pass
        // through.
        Event::listen(Login::class, FlagOutstandingContractConsent::class);

        // A payer's declaration at a public checkout becomes Consent rows
        // here (GitLab #2). Commerce emits the event and never touches this
        // module's tables — events are the only contract between modules
        // (ADR-0004), and the architecture test allows nothing else.
        Event::listen(CheckoutConsentGiven::class, [RecordCheckoutConsent::class, 'handle']);

        // ADR-0066 §6.2, D-CPL-10: the same act from Registration's form.
        // Its own event rather than a shared Platform one, so each module
        // keeps saying what it knows and nothing else.
        Event::listen(RegistrationConsentGiven::class, [RecordConsentDeclaration::class, 'handle']);

        // ADR-0059 / ADR-0066 spec §10: the three consent counters. Prompts
        // and grants share one bounded surface set so the dashboard can
        // divide one by the other — the refusal rate is derived, never
        // counted (D-CPL-17).
        Event::listen(ConsentPromptRaised::class, [RecordConsentMetrics::class, 'handle']);
        Event::listen(ConsentGranted::class, [RecordConsentMetrics::class, 'handle']);
        Event::listen(ConsentWithdrawn::class, [RecordConsentMetrics::class, 'handle']);

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
            // An `@if` over `daysOverdue`, not a ternary with `abs()` in it
            // (GitLab #9): every shipped default has to survive
            // `TemplateOverrideGuard`, because the editor seeds the override
            // textarea with exactly this text and an operator who adds a
            // signature must be able to save it. Whitespace around every
            // directive on purpose — a directive glued to a word is left as
            // literal text by Blade (.ai/rules/app-modules-src.md).
            bodies: [
                'de' => <<<'BLADE'
                    <p>Antrag {{ $requestId }} ({{ $requestType }}) — Frist {{ $deadline }}.</p>
                    @if ($daysLeft < 0)
                        <p>Überfällig seit {{ $daysOverdue }} Tag(en).</p>
                    @else
                        <p>Noch {{ $daysLeft }} Tag(e).</p>
                    @endif
                    <p>{{ $openModules }} Modul(e) haben noch nicht geantwortet.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Request {{ $requestId }} ({{ $requestType }}) — deadline {{ $deadline }}.</p>
                    @if ($daysLeft < 0)
                        <p>Overdue by {{ $daysOverdue }} day(s).</p>
                    @else
                        <p>{{ $daysLeft }} day(s) left.</p>
                    @endif
                    <p>{{ $openModules }} module(s) have not answered yet.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'requestId' => '01J8Z6QZ2R3S4T5U6V7W8X9Y0Z',
                'requestType' => 'export',
                'deadline' => '28.08.2026',
                'daysLeft' => 5,
                'daysOverdue' => 0,
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
     * Since GitLab #205 the contribution carries a second fact:
     * `outstandingUrl`, the page the payer has to settle an outstanding
     * *contract* agreement on before this purchase may proceed — null when
     * there is none, which is the normal case. It is a URL rather than a
     * policy for the same reason `required` is a boolean: Commerce refuses
     * the checkout without ever learning that a policy exists. Only
     * `LawfulBasis::Contract` raises it (`OutstandingConsents::
     * subjectHasOutstandingContract()`); a consent-based text is what the
     * `require-consent` route gate is for and must not stand between a payer
     * and an order they already owe.
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
            props: static function (array $context): array {
                $brandId = app(BrandContext::class)->id();

                if ($brandId === null) {
                    return [
                        'outstandingUrl' => null,
                        'required' => false,
                        'policies' => [],
                        'notices' => [],
                        'exemptionNotice' => CheckoutLegalTexts::EXEMPTION_NOTICE,
                    ];
                }

                $subjectId = auth()->id();

                return [
                    ...app(ConsentContribution::class)->propsFor(
                        self::checkoutBindingContext($brandId, $context),
                        $subjectId === null ? null : (string) $subjectId,
                    ),
                    'exemptionNotice' => CheckoutLegalTexts::EXEMPTION_NOTICE,
                ];
            },
            order: 10,
        );

        // Registration's own form declares `registration.consent` (its page,
        // its extension point — spec §7.1) and this fills it, mirroring
        // `checkout.consent` exactly. The context is the registration act's:
        // the event, the type being bought and the entry conditions it
        // carries. No exemption notice here — § 312g Abs. 2 Nr. 9 BGB is a
        // statement about an *order*, and the checkout is where it belongs.
        $this->app->make(SlotRegistry::class)->add(
            slot: 'registration.consent',
            module: 'compliance',
            component: 'compliance/RegistrationConsent',
            props: static function (array $context): array {
                $brandId = app(BrandContext::class)->id();

                if ($brandId === null) {
                    return ['outstandingUrl' => null, 'required' => false, 'policies' => [], 'notices' => []];
                }

                $subjectId = auth()->id();

                return app(ConsentContribution::class)->propsFor(
                    new BindingContext(
                        brandId: $brandId,
                        eventId: is_string($context['eventId'] ?? null) ? $context['eventId'] : null,
                        registrationTypeIds: self::stringList($context['registrationTypeIds'] ?? null),
                        entryConditionIds: self::stringList($context['entryConditionIds'] ?? null),
                        registrationId: is_string($context['registrationId'] ?? null) ? $context['registrationId'] : null,
                    ),
                    $subjectId === null ? null : (string) $subjectId,
                );
            },
            order: 10,
        );

        // #222: the operator decided "warn only, but with a bigger hint in
        // the UI" — no hard refusal at checkout, so the console has to be
        // loud instead, and loud means on every page an operator manages
        // selling for a brand from. Commerce's settlement console is one of
        // those, and Commerce may not ask Compliance anything directly
        // (ADR-0004, `tests/Unit/ArchitectureTest.php`) — so it declares the
        // slot and this fills it, the way every other cross-module panel on
        // this codebase works.
        //
        // `static` for the reason above: `SlotRegistry` is a singleton that
        // outlives the request under Octane.
        $this->app->make(SlotRegistry::class)->add(
            slot: 'admin.settlement-accounts',
            module: 'compliance',
            component: 'compliance/CheckoutLegalTextGapNotice',
            props: static fn (array $context): array => [
                'gaps' => array_map(
                    static fn (CheckoutLegalTextGapData $gap): array => $gap->toArray(),
                    CheckoutLegalTextGapData::gapsForAllBrands(app(CheckoutLegalTexts::class)),
                ),
                'roles' => array_map(
                    static fn (CheckoutLegalTextRoleData $role): array => $role->toArray(),
                    CheckoutLegalTextRoleData::roles(),
                ),
                // The console's own policy list, where the missing text is
                // created — a route name, resolved here, because Commerce's
                // page may not name a Compliance route either.
                'policiesUrl' => route('admin.policies.index', absolute: false),
            ],
            order: 1,
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

    /**
     * The binding context a checkout's slot render runs on (D-CPL-06).
     *
     * Commerce hands over the union of its order lines' opaque bags — the
     * registration-side facts frozen when the order was created — plus the
     * two facts it owns: the provider the payer chose in step one and the
     * order itself. Nothing in Commerce reads any of it; this is the first
     * place the keys mean anything.
     *
     * Every key is optional. A line with no bag (every line that existed
     * before this feature — there was no backfill) contributes nothing, and
     * the resolution then returns the unbound policies only, which is
     * exactly today's behaviour.
     *
     * @param  array<string, mixed>  $context
     */
    private static function checkoutBindingContext(string $brandId, array $context): BindingContext
    {
        /** @var array<string, mixed> $bag */
        $bag = is_array($context['bindingContext'] ?? null) ? $context['bindingContext'] : [];

        $bag['settlement_provider'] = is_string($context['settlementProvider'] ?? null)
            ? $context['settlementProvider']
            : null;
        $bag['order_id'] = is_string($context['orderId'] ?? null) ? $context['orderId'] : null;

        return BindingContext::ofArray($bag, $brandId);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
