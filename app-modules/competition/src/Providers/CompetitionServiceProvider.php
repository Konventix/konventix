<?php

declare(strict_types=1);

namespace Konventix\Competition\Providers;

use Illuminate\Support\Facades\Event;
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
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Listeners\ProjectAdmissionPresence;
use Konventix\Competition\Listeners\ProjectEligibilityOnRegistrationEvents;
use Konventix\Competition\Listeners\ProjectParticipantProfile;
use Konventix\Competition\Standings\Calculators\DoubleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\GroupStandingsCalculator;
use Konventix\Competition\Standings\Calculators\PointsStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SingleEliminationStandingsCalculator;
use Konventix\Competition\Standings\Calculators\SwissStandingsCalculator;
use Konventix\Competition\Standings\StandingsCalculatorRegistry;
use Konventix\Compliance\Gdpr\GdprHandlerRegistry;
use Konventix\Identity\Events\UserProfileUpdated;
use Konventix\Identity\Events\UserRegistered;
use Konventix\Messaging\Templates\MessageTemplate;
use Konventix\Messaging\Templates\TemplateRegistry;
use Konventix\Platform\Modules\ModuleServiceProvider;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;
use Konventix\Platform\Permissions\DefaultRole;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;

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
     * @return list<DefaultRole>
     */
    public function defaultRoles(): array
    {
        return [
            DefaultRole::of('competition.tournament-admin', 'Tournament Admin', ...Permission::cases()),
        ];
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

        // What the door has seen (design §6). A competition may require that
        // every roster member was scanned at the event entrance before the
        // team is checked in, and Admission owns that fact — so it arrives on
        // Admission's public events and is projected into this module's own
        // `competition_admission_presence` (ADR-0004). Nothing here ever
        // queries Admission.
        //
        // **These five ARE gated**, on the brand each event names, unlike the
        // two projections above: door presence is worth nothing to a brand
        // that runs no tournaments, it matters only for the hours of one
        // event, and #298 asks for the short circuit by name. The gate lives
        // inside the listener because these events arrive on the entrance
        // host, on a webhook and from a console command, none of which
        // carries a brand in context (ADR-0003).
        //
        // `AdmissionIssued` is listened to although §6 names only the holder
        // events: a self-bought ticket names its holder there and emits no
        // `AdmissionHolderAssigned` at all. See the listener's own note and
        // the design's §14.
        Event::listen(AdmissionIssued::class, [ProjectAdmissionPresence::class, 'handleIssued']);
        Event::listen(AdmissionHolderAssigned::class, [ProjectAdmissionPresence::class, 'handleHolderAssigned']);
        Event::listen(AdmissionHolderUnassigned::class, [ProjectAdmissionPresence::class, 'handleHolderUnassigned']);
        Event::listen(AdmissionCheckedIn::class, [ProjectAdmissionPresence::class, 'handleCheckedIn']);
        Event::listen(AdmissionRevoked::class, [ProjectAdmissionPresence::class, 'handleRevoked']);
        Event::listen(AdmissionOwnershipAnnounced::class, [ProjectAdmissionPresence::class, 'handleOwnershipAnnounced']);
    }

    /**
     * The two messages §5.4 owes people, and nothing else.
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
