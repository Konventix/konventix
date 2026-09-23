<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Actions\AddTeamMember;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Actions\UpdateDiscipline;
use Konventix\Competition\Data\DisciplineData;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\LinkedProvider;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;
use Konventix\Identity\Events\SocialIdentityLinked;
use Konventix\Identity\Events\SocialIdentityUnlinked;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;

/**
 * The linked-account signup rule (design §5.6): a discipline may require
 * everyone who plays it to have linked a provider account (Steam today).
 * Entering checks the whole roster. Joining a roster by code, by the
 * organizer's hand, by fill-up or by a move checks the person joining
 * against every contest the team holds a slot in. Nothing is retroactive:
 * switching the rule on, or unlinking later, removes nobody.
 *
 * Links arrive the only sanctioned way, on Identity's `SocialIdentityLinked`
 * (ADR-0004). The projection is ungated; the tests switch the module on
 * because the participant routes are behind `ensure-module:competition`.
 *
 * `Identity\Models\User` appears here, as in `SignupHttpTest`, only because
 * `actingAs()` needs a real account.
 *
 * Helper names are process-global across every module (.ai/rules/tests.md),
 * so the ones only this file uses are prefixed `linkedRule`.
 *
 * @param  array<string, mixed>  $attributes
 */
function linkedRuleCompetition(string $eventId, int $teamSize = 1, bool $requiresSteam = true, array $attributes = []): Competition
{
    $discipline = Discipline::factory()->teamSize($teamSize);

    if ($requiresSteam) {
        $discipline = $discipline->requiresLinkedProvider(LinkedProvider::Steam);
    }

    return Competition::factory()->create([
        'discipline_id' => $discipline->create()->getKey(),
        'event_id' => $eventId,
        ...$attributes,
    ]);
}

function linkedRuleLinkSteam(string $userId): void
{
    event(new SocialIdentityLinked($userId, 'steam', (string) fake()->unique()->numerify('765611980########')));
}

/**
 * The event as Competition learns of it, published, so that its public
 * tournament pages answer (design §10).
 */
function linkedRulePublishedEvent(Brand $brand, string $slug): string
{
    $eventId = (string) Str::ulid();

    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: $slug,
        name: 'SXLan 27',
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

    return $eventId;
}

/*
 * ---------------------------------------------------------------------------
 * Entering (EnterCompetition)
 * ---------------------------------------------------------------------------
 */

it('refuses an entry while a roster member has not linked Steam, naming only them, and takes it once they have', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 2);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $mate = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Halb verknüpft', $captain);
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $mate);

    try {
        app(EnterCompetition::class)->handle($competition, $team->refresh());
        $this->fail('The entry should have been refused.');
    } catch (CompetitionEntryException $e) {
        expect($e->missingLinkedProvider())->toBe(LinkedProvider::Steam)
            ->and($e->usersMissingTheLink())->toBe([$mate]);
    }

    expect(Entrant::query()->where('competition_id', $competition->id)->exists())->toBeFalse();

    linkedRuleLinkSteam($mate);

    $entrant = app(EnterCompetition::class)->handle($competition, $team->refresh());

    expect($entrant->state)->toBe(EntrantState::Registered);
});

it('checks the members an entry adds as well as those already on the team', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 2);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $added = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Zu zweit', $captain);

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team, [$captain, $added]))
        ->toThrow(CompetitionEntryException::class);

    // The transaction rolled the added member back with the refusal.
    expect($team->refresh()->memberUserIds())->toBe([$captain]);
});

it('asks nothing of a discipline without a requirement', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, requiresSteam: false);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Ohne Steam', $captain);

    expect(app(EnterCompetition::class)->handle($competition, $team)->state)->toBe(EntrantState::Registered);
});

/*
 * ---------------------------------------------------------------------------
 * Joining a roster (MaintainsTeamRoster::addRosterMember)
 * ---------------------------------------------------------------------------
 */

it('refuses a join by code for the unlinked person only, once the team holds a slot in a Steam competition', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 3);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $linked = competitionEligibleUser($eventId, $brand->id);
    $unlinked = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);
    linkedRuleLinkSteam($linked);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Steam-Pflicht', $captain);
    app(EnterCompetition::class)->handle($competition, $team);

    try {
        app(JoinTeamByCode::class)->handle($brand->id, $team->refresh()->invite_code, $unlinked);
        $this->fail('The join should have been refused.');
    } catch (CompetitionEntryException $e) {
        expect($e->missingLinkedProvider())->toBe(LinkedProvider::Steam)
            ->and($e->usersMissingTheLink())->toBe([$unlinked]);
    }

    app(JoinTeamByCode::class)->handle($brand->id, $team->refresh()->invite_code, $linked);

    expect($team->refresh()->memberUserIds())->toBe([$captain, $linked]);
});

it('lets an unlinked person join a team that has entered nothing yet, and entering then names them', function (): void {
    // Gather first, enter later: the same order the roster bound allows.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 2);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $unlinked = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Erst sammeln', $captain);
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $unlinked);

    expect($team->refresh()->memberUserIds())->toBe([$captain, $unlinked])
        ->and(fn () => app(EnterCompetition::class)->handle($competition, $team->refresh()))
        ->toThrow(CompetitionEntryException::class);
});

it('refuses the organizer adding an unlinked person too, with the name in the console', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 2);

    $captain = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Am Desk', $captain);
    app(EnterCompetition::class)->handle($competition, $team);

    $unlinked = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($unlinked, 'Ohne Steam', 'ohnesteam');

    expect(fn () => app(AddTeamMember::class)->handle($team->refresh(), $unlinked))
        ->toThrow(CompetitionEntryException::class);

    // Rows first: the admin host resolves no brand (.ai/rules/tests.md).
    [, $admin] = competitionOperator([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.manage-entrants',
    ]);

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competition-teams/'.$team->getKey().'/members', ['userId' => $unlinked])
        ->assertSessionHasErrors([
            'entrant' => 'Ohne Steam must link a Steam account first: a competition this team is entered in requires one.',
        ]);

    expect($team->refresh()->memberUserIds())->toBe([$captain]);
});

/*
 * ---------------------------------------------------------------------------
 * Nothing retroactive
 * ---------------------------------------------------------------------------
 */

it('touches no existing roster when the requirement is switched on, and applies from the next join', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 3, requiresSteam: false);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $mate = competitionEligibleUser($eventId, $brand->id);
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Schon drin', $captain);
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $mate);
    $entrant = app(EnterCompetition::class)->handle($competition, $team->refresh());
    $stateBefore = $entrant->state;

    /** @var Discipline $discipline */
    $discipline = Discipline::query()->findOrFail($competition->discipline_id);
    $data = DisciplineData::fromModel($discipline);

    // Not frozen, unlike the team size, although a competition runs it.
    app(UpdateDiscipline::class)->handle($discipline, new DisciplineData(
        kind: $data->kind,
        name: $data->name,
        slug: $data->slug,
        teamSize: $data->teamSize,
        avgMatchMinutes: $data->avgMatchMinutes,
        allowDraws: $data->allowDraws,
        gameId: $data->gameId,
        requiresLinkedProvider: LinkedProvider::Steam,
    ));

    expect($discipline->refresh()->requires_linked_provider)->toBe(LinkedProvider::Steam)
        ->and($entrant->refresh()->state)->toBe($stateBefore)
        ->and($team->refresh()->memberUserIds())->toBe([$captain, $mate]);

    $late = competitionEligibleUser($eventId, $brand->id);

    expect(fn () => app(JoinTeamByCode::class)->handle($brand->id, $team->refresh()->invite_code, $late))
        ->toThrow(CompetitionEntryException::class);
});

it('removes nobody from a roster when a member unlinks Steam later', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId);

    $captain = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Bleibt', $captain);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    event(new SocialIdentityUnlinked($captain, 'steam'));

    expect($entrant->refresh()->state)->toBe(EntrantState::Registered)
        ->and($team->refresh()->memberUserIds())->toBe([$captain]);
});

/*
 * ---------------------------------------------------------------------------
 * HTTP: German refusals with a link, English console
 * ---------------------------------------------------------------------------
 */

it('tells the viewer on the competition page, and refuses the entry in German naming the teammates', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = linkedRulePublishedEvent($brand, 'sxlan-steam');
    $competition = linkedRuleCompetition($eventId, teamSize: 2, attributes: ['slug' => 'cs2']);

    $captain = User::factory()->create();
    $mate = User::factory()->create();
    competitionEligibleUser($eventId, $brand->id, (string) $captain->getKey());
    competitionEligibleUser($eventId, $brand->id, (string) $mate->getKey());
    competitionParticipantName((string) $mate->getKey(), 'Mitspielerin Mia', 'mia');

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Kellerkinder', (string) $captain->getKey());
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, (string) $mate->getKey());

    $this->actingAs($captain)
        ->get('/events/sxlan-steam/tournaments/cs2')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.competition.requiredLinkedAccount', 'Steam')
            ->where('page.viewer.missingLinkedAccount', true));

    // Both unlinked: the captain reads "du" and the teammate's name.
    $this->actingAs($captain)
        ->post('/tournaments/'.$competition->id.'/entries', ['teamId' => $team->id])
        ->assertSessionHasErrors([
            'teamId' => 'This tournament needs a linked Steam account for everyone on the team – still missing: you, Mitspielerin Mia.',
        ]);

    linkedRuleLinkSteam((string) $mate->getKey());

    // Only the captain left: told directly.
    $this->actingAs($captain)
        ->post('/tournaments/'.$competition->id.'/entries', ['teamId' => $team->id])
        ->assertSessionHasErrors([
            'teamId' => 'This tournament needs you to have a linked Steam account.',
        ]);

    expect(Entrant::query()->where('competition_id', $competition->id)->exists())->toBeFalse();

    linkedRuleLinkSteam((string) $captain->getKey());

    $this->actingAs($captain)
        ->post('/tournaments/'.$competition->id.'/entries', ['teamId' => $team->id])
        ->assertSessionHasNoErrors();

    // A second full-page GET in one test needs the request boundary
    // (.ai/rules/tests.md).
    $this->app->forgetScopedInstances();

    $this->actingAs($captain)
        ->get('/events/sxlan-steam/tournaments/cs2')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page.viewer.missingLinkedAccount', false));
});

it('answers a refused join by code in German with the link marker, and keeps one sentence for every other refusal', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);
    $eventId = (string) Str::ulid();
    $competition = linkedRuleCompetition($eventId, teamSize: 3);

    $captain = competitionEligibleUser($eventId, $brand->id);
    linkedRuleLinkSteam($captain);
    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Steam-Pflicht', $captain);
    app(EnterCompetition::class)->handle($competition, $team);

    $joiner = User::factory()->create();
    competitionEligibleUser($eventId, $brand->id, (string) $joiner->getKey());

    $this->actingAs($joiner)
        ->post('/my/tournaments/join', ['code' => $team->refresh()->invite_code])
        ->assertSessionHasErrors([
            'code' => 'This team needs you to have a linked Steam account. Link it, then enter the code again.',
            'linkedAccount' => 'Steam',
        ]);

    // Somebody the code would not have let in anyway (no registration) gets
    // the usual single sentence, not the Steam one: the check runs last.
    $unregistered = User::factory()->create();

    $this->actingAs($unregistered)
        ->post('/my/tournaments/join', ['code' => $team->refresh()->invite_code])
        ->assertSessionHasErrors('code')
        ->assertSessionDoesntHaveErrors('linkedAccount');

    linkedRuleLinkSteam((string) $joiner->getKey());

    $this->actingAs($joiner)
        ->post('/my/tournaments/join', ['code' => $team->refresh()->invite_code])
        ->assertRedirect(route('competition.my.tournaments'));
});

it('saves the requirement from the console discipline form and refuses a provider it does not know', function (): void {
    $this->withBrand();
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create(['slug' => 'cs2']);

    $payload = [
        'kind' => 'video_game',
        'name' => 'Counter-Strike 2',
        'slug' => 'cs2',
        'teamSize' => 1,
        'avgMatchMinutes' => 40,
        'allowDraws' => false,
        'gameId' => null,
    ];

    $this->actingAs($admin)
        ->put('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey(), [...$payload, 'requiresLinkedProvider' => 'discord'])
        ->assertSessionHasErrors('requiresLinkedProvider');

    $this->actingAs($admin)
        ->put('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey(), [...$payload, 'requiresLinkedProvider' => 'steam'])
        ->assertSessionHasNoErrors();

    expect($discipline->refresh()->requires_linked_provider)->toBe(LinkedProvider::Steam);

    $this->actingAs($admin)
        ->put('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey(), [...$payload, 'requiresLinkedProvider' => null])
        ->assertSessionHasNoErrors();

    expect($discipline->refresh()->requires_linked_provider)->toBeNull();
});
