<?php

declare(strict_types=1);

namespace Konventix\Competition\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Admission\Events\AdmissionHolderUnassigned;
use Konventix\Admission\Events\AdmissionIssued;
use Konventix\Admission\Events\AdmissionOwnershipAnnounced;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Competition\Brackets\FormatRegistry;
use Konventix\Competition\Brackets\Formats\DoubleEliminationGenerator;
use Konventix\Competition\Brackets\Formats\GroupStageGenerator;
use Konventix\Competition\Brackets\Formats\RoundRobinGenerator;
use Konventix\Competition\Brackets\Formats\SingleEliminationGenerator;
use Konventix\Competition\Brackets\Formats\SwissGenerator;
use Konventix\Competition\Data\MyCompetitionsCardData;
use Konventix\Competition\Data\NextGamesCardData;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Events\EntrantCheckedIn;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Events\EntrantWithdrawn;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Hub\MyCompetitionsCard;
use Konventix\Competition\Hub\NextGamesCard;
use Konventix\Competition\Listeners\Metrics\RecordCompetitionMetrics;
use Konventix\Competition\Listeners\ProjectAdmissionPresence;
use Konventix\Competition\Listeners\ProjectEligibilityOnRegistrationEvents;
use Konventix\Competition\Listeners\ProjectEventSummary;
use Konventix\Competition\Listeners\ProjectLinkedIdentity;
use Konventix\Competition\Listeners\ProjectParticipantProfile;
use Konventix\Competition\Metrics\CompetitionGauges;
use Konventix\Competition\Metrics\CompetitionMetrics;
use Konventix\Competition\Seeding\RandomSeedSource;
use Konventix\Competition\Seeding\SeedSourceRegistry;
use Konventix\Competition\Seeding\SignupField;
use Konventix\Competition\Standings\Calculators\DoubleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\GroupStandingsCalculator;
use Konventix\Competition\Standings\Calculators\PointsStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SingleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SwissStandingsCalculator;
use Konventix\Competition\Standings\StandingsCalculatorRegistry;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventDetailsUpdated;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Events\EventUnpublished;
use Konventix\Identity\Events\AccountsMerged;
use Konventix\Identity\Events\SocialIdentityAnnounced;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Events\SocialIdentityUnlinked;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Observability\MetricCatalog;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;
use Spatie\Prometheus\Facades\Prometheus;

/**
 * Competition is Pennant-gated: a brand that runs no tournaments switches it
 * off and pays for none of it (ADR-0003).
 *
 * The eligibility projection is deliberately *not* gated — a brand switching
 * Competition on mid-event must find its existing registrations already
 * projected, or nobody could enter anything until they all re-confirmed.
 */
class CompetitionServiceProvider extends ModuleServiceProvider
{
    public static function moduleName(): string
    {
        return 'competition';
    }

    /**
     * The two default roles of design §11 (D-CMP-23, ADR-0045, #135).
     *
     * **Tournament Admin** holds all nine capabilities. **Tournament Desk**
     * runs a tournament on the day: it sees the competitions, checks teams
     * in, draws and times the matches, and records results. It does not
     * correct a recorded result or change a roster, because those are the two
     * operations with a blast radius. A correction cascades through the
     * bracket (§8.5), and a roster change moves people between sides. Both
     * stay with the admin.
     *
     * Everything a participant does (enter, join, report, confirm, check in
     * during the window) is not a capability at all. It follows from
     * membership in a side, captaincy and the §5.1 gate.
     *
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of('competition.tournament-admin', 'Tournament Admin', ...Permission::cases()),
            DefaultRole::of(
                'competition.tournament-desk',
                'Tournament Desk',
                Permission::ViewCompetitions,
                Permission::CheckInEntrants,
                Permission::RecordResults,
                Permission::ManageSchedule,
            ),
        ];
    }

    /**
     * The participant and hub budgets (rate limiters) and the metrics, on
     * top of the base provider's wiring.
     */
    public function boot(): void
    {
        parent::boot();

        $this->configureRateLimiting();
        $this->registerMetrics();
    }

    /**
     * ADR-0059, design §13: the two counters in the catalogue, recorded by
     * `RecordCompetitionMetrics` on this module's own events
     * (registerListeners()), and the two gauges answered by
     * `CompetitionGauges` at scrape time.
     */
    protected function registerMetrics(): void
    {
        $this->app->make(MetricCatalog::class)->register(...CompetitionMetrics::definitions());

        Prometheus::registerCollectorClasses([CompetitionGauges::class]);
    }

    /**
     * The one budget every participant write spends (routes/web): ten a
     * minute per account. Named, so it is Competition's own counter rather
     * than the one every unnamed `throttle:x,y` in the application shares
     * (an unnamed throttle keys on the account alone). Keyed on the account,
     * so a LAN hall behind one address does not share it; every route on it
     * is `auth`, so there always is one.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('competition-participant', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('competition-participant:'.($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown')));

        // The hub card's refresh (design §10a): one fetch a minute per open
        // tab, so twelve leaves room for a few tabs and a reconnect burst.
        RateLimiter::for('competition-hub', fn (Request $request): Limit => Limit::perMinute(12)
            ->by('competition-hub:'.($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown')));
    }

    /**
     * The bracket formats absorbed from LanBrackets (ADR-0019), registered
     * rather than switched on: a sixth format is one more `register()` call,
     * and LanBrackets' `race_heat`/`final_stage` stubs can arrive without
     * touching anything that already works.
     */
    protected function registerBindings(): void
    {
        $this->app->singleton(FormatRegistry::class, function (): FormatRegistry {
            $registry = new FormatRegistry;

            $registry->register(new SingleEliminationGenerator);
            $registry->register(new DoubleEliminationGenerator);
            $registry->register(new RoundRobinGenerator);
            $registry->register(new SwissGenerator);
            $registry->register(new GroupStageGenerator);

            return $registry;
        });

        $this->app->singleton(StandingsCalculatorRegistry::class, function (): StandingsCalculatorRegistry {
            $registry = new StandingsCalculatorRegistry;

            $registry->register(new SingleEliminationStandingsCalculator);
            $registry->register(new DoubleEliminationStandingsCalculator);
            $registry->register(new PointsStandingsCalculator);
            $registry->register(new SwissStandingsCalculator);
            $registry->register(new GroupStandingsCalculator);

            return $registry;
        });

        // Where a stage's field comes from (design §7.3). One entry today:
        // a stage seeded from signup, ordered at random until the desk drags
        // it (`ManualSeedSource`, which is an explicit override and therefore
        // never registered). `PreviousStageSeedSource` is the second entry
        // and the reason this is a registry at all — adding it is this one
        // `register()` call and nothing else, because `SeedStage` already
        // asks here and already passes nothing but the stage.
        $this->app->singleton(SeedSourceRegistry::class, function ($app): SeedSourceRegistry {
            $registry = new SeedSourceRegistry;

            $registry->register(new RandomSeedSource($app->make(SignupField::class)));

            return $registry;
        });
    }

    /**
     * Design §9: the two console screens this slice ships, in the operator
     * area (ADR-0029). Admin entries carry no brand gate — there is no brand
     * on that host — so the capability is the only filter, and the registry
     * applies it at render time.
     *
     * `order` sits with Resources' pair (40/41): both are things an
     * organizer configures per event rather than daily operations.
     */
    protected function registerNavigation(): void
    {
        $this->app->make(NavigationRegistry::class)->add(
            new NavigationItem(
                module: 'competition',
                label: 'Competitions',
                url: '/admin/competitions',
                icon: 'trophy',
                order: 50,
                permission: Permission::ViewCompetitions->value,
                area: NavigationItem::AREA_ADMIN,
            ),
            new NavigationItem(
                module: 'competition',
                label: 'Disciplines',
                url: '/admin/disciplines',
                icon: 'gamepad-2',
                order: 51,
                permission: Permission::ManageDisciplines->value,
                area: NavigationItem::AREA_ADMIN,
            ),
        );
    }

    /**
     * The two hub cards of design §10a (hub design §8, D-HUB-07): the
     * viewer's next matches with a nudge each, and their entries at the
     * running event. Registered at boot for every brand and hidden by the
     * registry wherever `competition` is off (ADR-0003); no capability,
     * because the hub is already only for admitted participants.
     *
     * The closures resolve their builders per request, so the singleton
     * registry captures nothing of one (`.ai/rules/providers.md`). Orders
     * 40 and 41 put them after Agenda's *Jetzt / Gleich* (20) and
     * Community's *Shoutbox* (30).
     */
    protected function registerHub(): void
    {
        $this->app->make(HubRegistry::class)->add(
            new HubCard(
                module: 'competition',
                key: 'competition.next-games',
                label: 'Nächste Spiele',
                component: 'competition/hub/NextGamesCard',
                payload: static fn (ActiveEventData $event, Authenticatable $user): NextGamesCardData => app(NextGamesCard::class)($event, $user),
                order: 40,
            ),
            new HubCard(
                module: 'competition',
                key: 'competition.my-competitions',
                label: 'Meine Competitions',
                component: 'competition/hub/MyCompetitionsCard',
                payload: static fn (ActiveEventData $event, Authenticatable $user): MyCompetitionsCardData => app(MyCompetitionsCard::class)($event, $user),
                order: 41,
            ),
        );
    }

    protected function registerListeners(): void
    {
        Event::listen(
            RegistrationSubmitted::class,
            [ProjectEligibilityOnRegistrationEvents::class, 'handleSubmitted'],
        );

        Event::listen(
            RegistrationConfirmed::class,
            [ProjectEligibilityOnRegistrationEvents::class, 'handleConfirmed'],
        );

        // Names for every participant and public page (design §10). Not
        // gated either, and for the same reason as the eligibility
        // projection above: a brand switching Competition on mid-event must
        // find the names already there, or every roster would render as
        // dashes until each person happened to edit their profile.
        Event::listen(UserRegistered::class, [ProjectParticipantProfile::class, 'handle']);
        Event::listen(UserProfileUpdated::class, [ProjectParticipantProfile::class, 'handle']);

        // Which provider accounts a person has linked, for a discipline's
        // `requires_linked_provider` (design §5.6). Global, like the names
        // above, because the link belongs to the person and not to a brand.
        // These events carry no brand, so the gate asks whether any brand
        // runs tournaments at all. The listener's docblock explains that,
        // and why the unlink handler always applies.
        // `identity:announce-social-identities` is the backfill.
        Event::listen(SocialIdentityLinked::class, [ProjectLinkedIdentity::class, 'handleLinked']);
        Event::listen(SocialIdentityUnlinked::class, [ProjectLinkedIdentity::class, 'handleUnlinked']);
        Event::listen(SocialIdentityAnnounced::class, [ProjectLinkedIdentity::class, 'handleAnnounced']);
        Event::listen(AccountsMerged::class, [ProjectLinkedIdentity::class, 'handleAccountsMerged']);

        // The event's slug and name for the public tournament pages (design
        // §10): `/events/{slug}/tournaments` resolves against this module's
        // own copy, because Event's model is out of reach (ADR-0004). Ungated
        // for the same reason as the two projections above;
        // `event:replay-details` backfills it.
        Event::listen(EventCreated::class, [ProjectEventSummary::class, 'handleCreated']);
        Event::listen(EventDetailsUpdated::class, [ProjectEventSummary::class, 'handleDetailsUpdated']);
        Event::listen(EventRescheduled::class, [ProjectEventSummary::class, 'handleRescheduled']);

        // …and its lifecycle state, which decides whether those pages are
        // public at all: a draft event's tournaments must not be reachable
        // by guessing a slug (Event's own landing page 404s it). Backfilled
        // by `event:replay-details --with-state`.
        Event::listen(EventAnnounced::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventPublished::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventUnpublished::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventStarted::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventConcluded::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventCancelled::class, [ProjectEventSummary::class, 'handleLifecycle']);
        Event::listen(EventArchived::class, [ProjectEventSummary::class, 'handleLifecycle']);

        // What the door has seen (design §6). A competition may require that
        // every roster member was scanned at the event entrance before the
        // team is checked in, and Admission owns that fact — so it arrives on
        // Admission's public events and is projected into this module's own
        // `competition_admission_presence` (ADR-0004). Nothing here ever
        // queries Admission.
        //
        // **These six ARE gated**, on the brand each event names, unlike the
        // two projections above: door presence is worth nothing to a brand
        // that runs no tournaments, and #298 asks for the short circuit by
        // name. The gate lives inside the listener because these events
        // arrive on the entrance host, on a webhook and from a console
        // command, none of which carries a brand in context (ADR-0003). What
        // it costs is not nothing: `AdmissionOwnershipAnnounced` carries no
        // scan timestamp, so a brand that switches the module on mid-event
        // cannot recover `checked_in_at` — the listener's class docblock has
        // that in full.
        //
        // `AdmissionHolderAssigned` is the main path and §6 is right about
        // it: a registration-derived admission is issued with `holder_user_id`
        // null and is named later. The other two are additions for the cases
        // that path never reaches — a crew badge, which names its holder on
        // `AdmissionIssued` and emits nothing else, and the #292 backfill,
        // which is the only way an admission older than this read model
        // enters it. Resources consumes the same five (`ResourcesServiceProvider`).
        // See the listener's own notes and the design's §14.
        Event::listen(AdmissionIssued::class, [ProjectAdmissionPresence::class, 'handleIssued']);
        Event::listen(AdmissionHolderAssigned::class, [ProjectAdmissionPresence::class, 'handleHolderAssigned']);
        Event::listen(AdmissionHolderUnassigned::class, [ProjectAdmissionPresence::class, 'handleHolderUnassigned']);
        Event::listen(AdmissionCheckedIn::class, [ProjectAdmissionPresence::class, 'handleCheckedIn']);
        Event::listen(AdmissionRevoked::class, [ProjectAdmissionPresence::class, 'handleRevoked']);
        Event::listen(AdmissionOwnershipAnnounced::class, [ProjectAdmissionPresence::class, 'handleOwnershipAnnounced']);

        // The recorders behind §13's two counters, on this module's own
        // events. They need no gate: these events only exist where a brand
        // runs tournaments.
        Event::listen(EntrantRegistered::class, [RecordCompetitionMetrics::class, 'handleEntrantRegistered']);
        Event::listen(EntrantCheckedIn::class, [RecordCompetitionMetrics::class, 'handleEntrantCheckedIn']);
        Event::listen(EntrantWithdrawn::class, [RecordCompetitionMetrics::class, 'handleEntrantWithdrawn']);
        Event::listen(MatchScheduled::class, [RecordCompetitionMetrics::class, 'handleMatchScheduled']);
        Event::listen(MatchFinished::class, [RecordCompetitionMetrics::class, 'handleMatchFinished']);
    }

    /**
     * The two messages §5.4 owes people, plus the one §8.2 does.
     *
     * Participant copy is German (ADR-0001) and so is the default locale;
     * the English bodies exist for an account whose locale says so.
     *
     * Two keys rather than one template branching on `@if ($filled)`: the
     * caller knows which of the two happened, and a Blade directive glued to
     * a neighbouring word is left as literal text
     * (.ai/rules/app-modules-src.md) — a trap not worth stepping near for a
     * branch that buys nothing.
     */
    protected function registerMessageTemplates(): void
    {
        $registry = $this->app->make(TemplateRegistry::class);

        $registry->register(new MessageTemplate(
            key: 'competition.roster-filled',
            subjects: [
                'de' => 'Du spielst bei {{ $competitionName }} für {{ $teamName }}',
                'en' => 'You are playing {{ $competitionName }} for {{ $teamName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Hallo,</p>
                    <p>{{ $detail }}</p>
                    <p>Turnier: <strong>{{ $competitionName }}</strong><br>
                    Team: <strong>{{ $teamName }}</strong></p>
                    <p>Deine Teams und Anmeldungen findest du unter „Meine Turniere".</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>{{ $detail }}</p>
                    <p>Tournament: <strong>{{ $competitionName }}</strong><br>
                    Team: <strong>{{ $teamName }}</strong></p>
                    <p>Your teams and entries are under "My tournaments".</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'competitionName' => 'Counter-Strike 2 — 5v5',
                'teamName' => 'Die Kellerkinder',
                'detail' => 'Die Orga hat dich für dieses Turnier ins Team aufgenommen.',
            ],
        ));

        /*
         * "…the other side is notified" (§8.2, step 1). Sent to every roster
         * member of the opposing side rather than to its captain alone: the
         * person who can confirm is whoever is sitting at a keyboard, and a
         * match waiting on one absent captain is the stall this exists to
         * avoid.
         *
         * The desk is *not* notified here, although §8.2 says it is told
         * about a dispute. Competition keeps no list of who is on the desk —
         * capabilities are per brand role (ADR-0013) and `SendMessage`
         * addresses a person — so the desk hears about a dispute from its own
         * screen and from §13's open-dispute alert. Recorded in the design's
         * §14.
         */
        $registry->register(new MessageTemplate(
            key: 'competition.result-reported',
            subjects: [
                'de' => 'Ergebnis gemeldet: {{ $competitionName }}',
                'en' => 'A result was reported: {{ $competitionName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Hallo,</p>
                    <p>{{ $detail }}</p>
                    <p>Turnier: <strong>{{ $competitionName }}</strong><br>
                    Gegner: <strong>{{ $teamName }}</strong></p>
                    <p>Unter „Meine Turniere" kannst du das Ergebnis bestaetigen oder widersprechen.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>{{ $detail }}</p>
                    <p>Tournament: <strong>{{ $competitionName }}</strong><br>
                    Opponent: <strong>{{ $teamName }}</strong></p>
                    <p>Confirm it or report your own result under "My tournaments".</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'competitionName' => 'Counter-Strike 2 — 5v5',
                'teamName' => 'Die Kellerkinder',
                'detail' => 'Euer Gegner hat ein Ergebnis gemeldet.',
            ],
        ));

        $registry->register(new MessageTemplate(
            key: 'competition.entry-declined',
            subjects: [
                'de' => '{{ $teamName }} startet nicht bei {{ $competitionName }}',
                'en' => '{{ $teamName }} will not play {{ $competitionName }}',
            ],
            bodies: [
                'de' => <<<'BLADE'
                    <p>Hallo,</p>
                    <p>{{ $detail }}</p>
                    <p>Turnier: <strong>{{ $competitionName }}</strong><br>
                    Team: <strong>{{ $teamName }}</strong></p>
                    <p>Der Platz ist wieder frei. Wenn ihr vollzählig werdet, sprecht die Orga an.</p>
                    BLADE,
                'en' => <<<'BLADE'
                    <p>Hello,</p>
                    <p>{{ $detail }}</p>
                    <p>Tournament: <strong>{{ $competitionName }}</strong><br>
                    Team: <strong>{{ $teamName }}</strong></p>
                    <p>The slot is free again. Talk to the organizers if you can field a full roster.</p>
                    BLADE,
            ],
            defaultLocale: 'de',
            sample: [
                'competitionName' => 'Counter-Strike 2 — 5v5',
                'teamName' => 'Die Kellerkinder',
                'detail' => 'Das Team war beim Anmeldeschluss nicht vollzählig.',
            ],
        ));
    }

    /**
     * Same shape as Staffing's, and registered for the same reason.
     */
    protected function registerGdprExporters(): void
    {
        $this->app->make(GdprHandlerRegistry::class)->register(new CompetitionGdprHandler);
    }
}
