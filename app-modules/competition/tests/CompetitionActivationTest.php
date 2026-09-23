<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Admission\Events\AdmissionHolderAssigned;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Models\AdmissionPresence;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\LinkedIdentity;
use Konventix\Competition\Models\Stage;
use Konventix\Competition\Providers\CompetitionServiceProvider;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Models\User;
use Konventix\Platform\Actions\UpdateBrandModules;
use Konventix\Platform\Hub\HubCard;
use Konventix\Platform\Hub\HubRegistry;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Permission as PermissionModel;
use Konventix\Platform\Models\Role;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;

/**
 * What the module *is* to a brand (design §11, §13, ADR-0003, ADR-0045): the
 * capabilities and the two default roles, and, further down, what a brand
 * with `competition` switched off sees of it on its own host and on the
 * operator console.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */
function activationTestRole(Brand $brand, string $key): ?Role
{
    return Role::query()->where('brand_id', $brand->getKey())->where('default_key', $key)->first();
}

/**
 * @return list<string>
 */
function activationTestGrants(?Role $role): array
{
    return $role?->permissions->pluck('name')->sort()->values()->all() ?? [];
}

describe('capabilities and roles (§11)', function (): void {
    it('declares the nine capabilities §11 counts, the three §11 adds among them', function (): void {
        expect(array_column(Permission::cases(), 'value'))->toBe([
            'competition.view-competitions',
            'competition.manage-disciplines',
            'competition.manage-competitions',
            'competition.manage-entrants',
            'competition.check-in-entrants',
            'competition.manage-schedule',
            'competition.record-results',
            'competition.correct-results',
            'competition.view-entrant-contacts',
        ]);
    });

    it('lets permissions:sync pick the three new capabilities up', function (): void {
        $this->artisan('permissions:sync', ['--no-roles' => true])->assertSuccessful();

        $synced = PermissionModel::query()->where('guard_name', 'web')->pluck('name')->all();

        expect($synced)->toContain('competition.manage-schedule')
            ->and($synced)->toContain('competition.check-in-entrants')
            ->and($synced)->toContain('competition.view-entrant-contacts');
    });

    it('ships Tournament Admin with all nine and Tournament Desk with the day-of four', function (): void {
        $roles = (new CompetitionServiceProvider(app()))->defaultRoles();

        expect($roles)->toHaveCount(2)
            ->and($roles[0]->key)->toBe('competition.tournament-admin')
            ->and($roles[0]->name)->toBe('Tournament Admin')
            ->and($roles[0]->permissions)->toBe(array_column(Permission::cases(), 'value'))
            ->and($roles[1]->key)->toBe('competition.tournament-desk')
            ->and($roles[1]->name)->toBe('Tournament Desk')
            // §11's table, in the order the provider lists them.
            ->and($roles[1]->permissions)->toBe([
                'competition.view-competitions',
                'competition.check-in-entrants',
                'competition.record-results',
                'competition.manage-schedule',
            ]);
    });

    it('keeps corrections and roster changes away from the desk (#135)', function (): void {
        $desk = (new CompetitionServiceProvider(app()))->defaultRoles()[1];

        expect($desk->permissions)->not->toContain(Permission::CorrectResults->value)
            ->and($desk->permissions)->not->toContain(Permission::ManageEntrants->value)
            ->and($desk->permissions)->not->toContain(Permission::ViewEntrantContacts->value);
    });

    it('materializes both roles when a brand activates the module, and neither before', function (): void {
        $brand = Brand::factory()->create(['slug' => 'activation-roles']);

        expect(activationTestRole($brand, 'competition.tournament-admin'))->toBeNull()
            ->and(activationTestRole($brand, 'competition.tournament-desk'))->toBeNull();

        app(UpdateBrandModules::class)->handle($brand, ['competition' => true]);

        // Sorted by name, because the relation reads without an ORDER BY.
        expect(activationTestGrants(activationTestRole($brand, 'competition.tournament-admin')))->toBe([
            'competition.check-in-entrants',
            'competition.correct-results',
            'competition.manage-competitions',
            'competition.manage-disciplines',
            'competition.manage-entrants',
            'competition.manage-schedule',
            'competition.record-results',
            'competition.view-competitions',
            'competition.view-entrant-contacts',
        ])
            ->and(activationTestGrants(activationTestRole($brand, 'competition.tournament-desk')))->toBe([
                'competition.check-in-entrants',
                'competition.manage-schedule',
                'competition.record-results',
                'competition.view-competitions',
            ]);
    });
});

/*
 * ---------------------------------------------------------------------------
 * The module switched off (ADR-0003, design §9, §13)
 * ---------------------------------------------------------------------------
 *
 * Every test below builds the same tournament and then looks at it from a
 * brand that has not activated `competition`. Each one ends by switching the
 * module on and asking again, so a 404 cannot pass because the route was
 * broken all along.
 */

/**
 * A published event with a published competition, a stage, an entrant and a
 * match, all on `$brand`, made the way the rest of this suite makes them.
 *
 * @return array{eventId: string, competition: Competition, stage: Stage, entrant: Entrant, match: CompetitionMatch, discipline: Discipline}
 */
function activationTestTournament(Brand $brand): array
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: 'sxlan-off',
        name: 'SXLan Off',
        startsAt: '2026-11-20 10:00:00',
        endsAt: '2026-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    event(new EventPublished(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        publishedAt: '2026-10-01T10:00:00+00:00',
    ));

    $discipline = Discipline::factory()->create(['name' => 'Rocket Cars']);

    $competition = Competition::factory()
        ->forEvent($eventId)
        ->status(CompetitionStatus::Published)
        ->create(['name' => 'Off Cup', 'slug' => 'off-cup', 'discipline_id' => $discipline->getKey()]);

    $stage = Stage::factory()->create(['competition_id' => $competition->getKey()]);

    $team = CompetitionTeam::factory()->forEvent($eventId)->create(['name' => 'Off Team']);

    $entrant = Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'competition_team_id' => $team->getKey(),
    ]);

    $match = CompetitionMatch::factory()->create([
        'competition_id' => $competition->getKey(),
        'stage_id' => $stage->getKey(),
    ]);

    return [
        'eventId' => $eventId,
        'competition' => $competition,
        'stage' => $stage,
        'entrant' => $entrant,
        'match' => $match,
        'discipline' => $discipline,
    ];
}

describe('with competition off for the brand (§13)', function (): void {
    it('answers 404 on every page and write of the brand host', function (): void {
        $brand = $this->withBrand();
        $world = activationTestTournament($brand);
        $user = User::factory()->create();

        $competitionId = (string) $world['competition']->getKey();
        $matchId = (string) $world['match']->getKey();

        // The public pages, then the signed-in ones, then a write: all behind
        // `ensure-module:competition`, which the base provider adds (ADR-0003).
        $this->get('/events/sxlan-off/tournaments')->assertNotFound();
        $this->get('/events/sxlan-off/tournaments/off-cup')->assertNotFound();

        $this->actingAs($user)->get('/my/tournaments')->assertNotFound();
        $this->actingAs($user)->get('/my/tournaments/join')->assertNotFound();
        $this->actingAs($user)->get('/live/next-games')->assertNotFound();
        $this->actingAs($user)->post('/tournaments/'.$competitionId.'/interest', ['note' => null])->assertNotFound();
        $this->actingAs($user)->post('/tournaments/'.$competitionId.'/matches/'.$matchId.'/confirm')->assertNotFound();

        // The control: the same index answers once the brand switches it on.
        $this->activateModule('competition', $brand);

        $this->get('/events/sxlan-off/tournaments')->assertOk();
    });

    it('contributes no brand navigation and no hub card', function (): void {
        $brand = $this->withBrand();
        $user = User::factory()->create();

        $competitionNav = fn () => app(NavigationRegistry::class)->visible()
            ->filter(fn (NavigationItem $item): bool => $item->module === 'competition');
        $competitionCards = fn () => app(HubRegistry::class)->visibleCards($user)
            ->filter(fn (HubCard $card): bool => $card->module === 'competition')
            ->map(fn (HubCard $card): string => $card->key)
            ->values()
            ->all();

        expect($competitionNav())->toHaveCount(0)
            ->and($competitionCards())->toBe([]);

        $this->activateModule('competition', $brand);

        // Competition has no brand-area entry at all (its screens are the
        // console's and the pages a link leads to), so the brand nav stays
        // empty; the two hub cards are what activation brings.
        expect($competitionNav())->toHaveCount(0)
            ->and($competitionCards())->toBe(['competition.next-games', 'competition.my-competitions']);
    });

    it('short-circuits the listeners that store anything for the brand', function (): void {
        $brand = $this->withBrand();
        $eventId = (string) Str::ulid();
        $admissionId = (string) Str::ulid();
        $userId = (string) Str::ulid();

        // Door presence is gated on the brand the event names (design §6).
        event(new AdmissionHolderAssigned(
            admissionId: $admissionId,
            registrationId: (string) Str::ulid(),
            eventId: $eventId,
            brandId: (string) $brand->getKey(),
            holderUserId: $userId,
            viaClaimLink: false,
            assignedAt: now()->toIso8601String(),
        ));

        // The linked-account projection is gated on any brand running the
        // module at all (design §5.6), and none does here.
        event(new SocialIdentityLinked($userId, 'steam', '76561198000000099'));

        expect(AdmissionPresence::withoutBrandScope()->where('admission_id', $admissionId)->exists())->toBeFalse()
            ->and(LinkedIdentity::query()->where('user_id', $userId)->exists())->toBeFalse();

        // The eligibility projection is ungated on purpose (design §14): a
        // brand switching the module on mid-event must find its confirmed
        // registrations already there.
        $eligible = competitionEligibleUser($eventId, (string) $brand->getKey());

        expect(EligibleRegistration::query()->where('user_id', $eligible)->where('is_confirmed', true)->exists())->toBeTrue();

        // The control: with the module on, the same two events are stored.
        $this->activateModule('competition', $brand);

        event(new AdmissionHolderAssigned(
            admissionId: $admissionId,
            registrationId: (string) Str::ulid(),
            eventId: $eventId,
            brandId: (string) $brand->getKey(),
            holderUserId: $userId,
            viaClaimLink: false,
            assignedAt: now()->toIso8601String(),
        ));
        event(new SocialIdentityLinked($userId, 'steam', '76561198000000099'));

        expect(AdmissionPresence::withoutBrandScope()->where('admission_id', $admissionId)->value('holder_user_id'))->toBe($userId)
            ->and(LinkedIdentity::query()->where('user_id', $userId)->exists())->toBeTrue();
    });

    it('hides the brand from the operator console, where every row answers 404', function (): void {
        $brand = $this->withBrand();
        $world = activationTestTournament($brand);

        // Every row above exists before the console request: a request to the
        // admin host resolves no brand (.ai/rules/tests.md).
        [, $admin] = competitionOperator(
            ['platform.access-admin', ...array_column(Permission::cases(), 'value')],
            activateCompetition: false,
        );

        $console = fn (string $path): string => 'http://admin.tournaments.test/admin'.$path;

        $competitionId = (string) $world['competition']->getKey();

        // The lists answer, and leave the brand out: no rows, no brand to
        // pick for a new competition or discipline.
        $this->actingAs($admin)->get($console('/competitions'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/competitions/Index')
                ->has('competitions.data', 0)
                ->where('brands', []));

        $this->actingAs($admin)->get($console('/disciplines'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/disciplines/Index')
                ->has('disciplines.data', 0)
                ->where('brands', []));

        foreach ([
            ['get', '/competitions/'.$competitionId.'/edit'],
            ['get', '/competitions/'.$competitionId.'/entrants'],
            ['get', '/competitions/'.$competitionId.'/check-in'],
            ['get', '/competitions/'.$competitionId.'/desk'],
            ['post', '/competitions/'.$competitionId.'/transition', ['status' => 'signup_closed']],
            ['get', '/disciplines/'.$world['discipline']->getKey().'/edit'],
            ['post', '/entrants/'.$world['entrant']->getKey().'/withdraw'],
            ['post', '/competition-stages/'.$world['stage']->getKey().'/generate'],
            ['post', '/competition-matches/'.$world['match']->getKey().'/record', ['scores' => [1 => 1, 2 => 0]]],
        ] as $endpoint) {
            [$method, $path] = $endpoint;
            $response = $this->actingAs($admin)->{$method}($console($path), $endpoint[2] ?? []);

            expect($response->status())->toBe(404, strtoupper($method).' '.$path.' answered '.$response->status());
        }

        // The control: the same editor opens once the brand switches it on.
        $this->activateModule('competition', $brand);

        $this->actingAs($admin)->get($console('/competitions/'.$competitionId.'/edit'))->assertOk();
    });
});
