<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Events\CompetitionPublished;
use Konventix\Competition\Events\CompetitionRescheduled;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;
use Konventix\Platform\Navigation\NavigationItem;
use Konventix\Platform\Navigation\NavigationRegistry;

/**
 * The competition console (design §9) and the lifecycle endpoint behind its
 * buttons (§4) on the operator host.
 *
 * Every row is created **before** the admin-host request: such a request
 * resolves no brand and `BrandScope` hardens brand-owned queries to empty
 * (.ai/rules/tests.md). The event picker is fed through Platform's
 * `ConsoleEventSources` because this module keeps no event read model
 * (ADR-0004) — `competitionConsoleEvent()` stands in for whichever module
 * owns events.
 */
function competitionAdminPayload(string $eventId, string $disciplineId, array $overrides = []): array
{
    return array_merge([
        'eventId' => $eventId,
        'disciplineId' => $disciplineId,
        'name' => 'Main Cup',
        'slug' => 'main-cup',
        'description' => null,
        'rules' => null,
        'maxEntrants' => 16,
        'signupOpensAt' => null,
        'signupClosesAt' => null,
        'startsAt' => null,
        'stageName' => 'Playoffs',
        'stageFormat' => 'single_elimination',
        'stageSettings' => null,
        'checkInMode' => 'none',
        'checkInOpensMinutesBefore' => 60,
        'requiresEventCheckIn' => false,
        'resultSubmissionMode' => 'desk_only',
        'proofPolicy' => 'none',
    ], $overrides);
}

it('403s the competition console without the view capability', function (): void {
    [, $admin] = competitionOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions')
        ->assertForbidden();
});

it('lists competitions with status, the field against the limit, and the next move', function (): void {
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create(['name' => 'Rocket Cars']);

    $competition = Competition::factory()
        ->forEvent($eventId)
        ->status(CompetitionStatus::Published)
        ->create([
            'name' => 'Main Cup',
            'slug' => 'main-cup',
            'discipline_id' => $discipline->getKey(),
            'max_entrants' => 16,
        ]);

    Stage::factory()->format('double_elimination')->create(['competition_id' => $competition->getKey()]);

    Entrant::factory()->count(2)->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Registered,
    ]);
    // Holds a slot against the limit until it is filled or declined (§5.4).
    Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Incomplete,
    ]);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/Index')
            ->has('competitions.data', 1)
            ->where('competitions.data.0.name', 'Main Cup')
            ->where('competitions.data.0.status', 'published')
            ->where('competitions.data.0.eventName', 'SXLan 2027')
            ->where('competitions.data.0.disciplineName', 'Rocket Cars')
            ->where('competitions.data.0.stageFormat', 'double_elimination')
            ->where('competitions.data.0.entrants', 3)
            ->where('competitions.data.0.maxEntrants', 16)
            ->where('competitions.data.0.nextStatus', 'signup_closed')
            ->where('competitions.data.0.nextLabel', 'Close signup'));
});

it('creates a competition as a draft together with its first stage', function (): void {
    // Design §7.1: the format lives on the stage, so the form creates both.
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create();

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competitions', competitionAdminPayload(
            $eventId,
            (string) $discipline->getKey(),
            ['brand_id' => $brand->getKey(), 'stageFormat' => 'round_robin', 'startsAt' => '2027-06-16T18:00:00+02:00'],
        ))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $competition = Competition::withoutBrandScope()->where('slug', 'main-cup')->sole();
    $stage = Stage::withoutBrandScope()->where('competition_id', $competition->getKey())->sole();

    expect($competition->brand_id)->toBe($brand->getKey())
        ->and($competition->status)->toBe(CompetitionStatus::Draft)
        ->and($competition->published_at)->toBeNull()
        ->and($competition->max_entrants)->toBe(16)
        ->and($competition->check_in_mode)->toBe(CheckInMode::None)
        ->and($competition->result_submission_mode)->toBe(ResultSubmissionMode::DeskOnly)
        ->and($competition->proof_policy)->toBe(ProofPolicy::None)
        // Anchored to UTC before it reached the column (.ai/rules/js.md).
        ->and($competition->starts_at?->toIso8601String())->toBe('2027-06-16T16:00:00+00:00')
        ->and($stage->brand_id)->toBe($brand->getKey())
        ->and($stage->format)->toBe('round_robin')
        ->and($stage->sequence)->toBe(1);
});

it('refuses an event the console does not know and a format nothing is registered for', function (): void {
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create();

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competitions', competitionAdminPayload(
            (string) Str::ulid(),
            (string) $discipline->getKey(),
            ['brand_id' => $brand->getKey()],
        ))
        ->assertSessionHasErrors('eventId');

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competitions', competitionAdminPayload(
            $eventId,
            (string) $discipline->getKey(),
            ['brand_id' => $brand->getKey(), 'stageFormat' => 'best_of_vibes'],
        ))
        ->assertSessionHasErrors('stageFormat');

    expect(Competition::withoutBrandScope()->where('slug', 'main-cup')->exists())->toBeFalse();
});

it('saves the editor and announces a moved start to the programme', function (): void {
    // Agenda's programme projects `CompetitionRescheduled` (timetable design
    // §4) — but only for a competition the world has heard of.
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create();
    $competition = Competition::factory()
        ->forEvent($eventId)
        ->status(CompetitionStatus::Published)
        ->create([
            'name' => 'Main Cup',
            'slug' => 'main-cup',
            'discipline_id' => $discipline->getKey(),
            'starts_at' => now()->addWeek(),
        ]);
    Stage::factory()->create(['competition_id' => $competition->getKey()]);

    Event::fake([CompetitionRescheduled::class]);

    $this->actingAs($admin)
        ->put('http://admin.tournaments.test/admin/competitions/'.$competition->getKey(), competitionAdminPayload(
            $eventId,
            (string) $discipline->getKey(),
            [
                'name' => 'Main Cup 2027',
                'rules' => 'Best of three.',
                'startsAt' => '2027-06-16T18:00:00+02:00',
                'checkInMode' => 'desk',
                'resultSubmissionMode' => 'participants',
                'proofPolicy' => 'required',
            ],
        ))
        ->assertSessionHasNoErrors()
        ->assertRedirect('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/edit');

    expect($competition->refresh()->name)->toBe('Main Cup 2027')
        ->and($competition->rules)->toBe('Best of three.')
        ->and($competition->check_in_mode)->toBe(CheckInMode::Desk)
        ->and($competition->result_submission_mode)->toBe(ResultSubmissionMode::Participants)
        ->and($competition->proof_policy)->toBe(ProofPolicy::Required)
        ->and($competition->starts_at?->toIso8601String())->toBe('2027-06-16T16:00:00+00:00')
        // The lifecycle is not a form field.
        ->and($competition->status)->toBe(CompetitionStatus::Published);

    Event::assertDispatched(CompetitionRescheduled::class, fn (CompetitionRescheduled $e): bool => $e->competitionId === $competition->id
        && $e->startsAt === '2027-06-16T16:00:00+00:00');
});

it('moves the lifecycle through one endpoint and refuses an illegal move', function (): void {
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    [, $admin] = competitionOperator();

    $competition = Competition::factory()
        ->forEvent($eventId)
        ->status(CompetitionStatus::Draft)
        ->create(['slug' => 'main-cup']);

    Event::fake([CompetitionPublished::class]);

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/transition', [
            'status' => 'published',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Published);
    Event::assertDispatched(CompetitionPublished::class);

    // Published → Running is not an arrow (§4): signup has to be closed
    // first, and the field has to have a drawn bracket.
    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/transition', [
            'status' => 'running',
        ])
        ->assertSessionHasErrors('status');

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Published);
});

it('403s the lifecycle endpoint without the manage capability', function (): void {
    $brand = $this->withBrand();
    competitionConsoleEvent($brand);
    [, $admin] = competitionOperator(['platform.access-admin', 'competition.view-competitions']);

    $competition = Competition::factory()->status(CompetitionStatus::Draft)->create();

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/transition', [
            'status' => 'published',
        ])
        ->assertForbidden();

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Draft);
});

it('renders the create and edit pages with the Data object and the arrows out of the state', function (): void {
    // A page added this session needs `npm run build` first (.ai/rules/tests.md).
    $brand = $this->withBrand();
    $eventId = competitionConsoleEvent($brand);
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create(['name' => 'Rocket Cars']);
    $competition = Competition::factory()
        ->forEvent($eventId)
        ->status(CompetitionStatus::SignupClosed)
        ->create([
            'name' => 'Main Cup',
            'slug' => 'main-cup',
            'discipline_id' => $discipline->getKey(),
        ]);
    Stage::factory()->format('swiss')->create(['competition_id' => $competition->getKey()]);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/Create')
            ->has('events', 1)
            ->has('disciplines', 1));

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/edit')
        ->assertOk()
        ->assertSee('Main Cup')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/Edit')
            ->where('competition.id', (string) $competition->getKey())
            ->where('competition.stageFormat', 'swiss')
            ->where('competition.status', 'signup_closed')
            ->where('competition.stageGenerated', false)
            ->where('transitions', [
                ['status' => 'published', 'label' => 'Reopen signup'],
                ['status' => 'running', 'label' => 'Start'],
                ['status' => 'cancelled', 'label' => 'Cancel'],
            ]));
});

it('contributes two admin navigation entries gated by capability', function (): void {
    // Admin-area entries skip the brand gate (there is no brand on the admin
    // host) and rely on their capability — `NavigationRegistry`.
    $entries = app(NavigationRegistry::class)->all()
        ->filter(fn (NavigationItem $item): bool => $item->module === 'competition' && $item->area === NavigationItem::AREA_ADMIN)
        ->values();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->label)->toBe('Competitions')
        ->and($entries[0]->url)->toBe('/admin/competitions')
        ->and($entries[0]->permission)->toBe('competition.view-competitions')
        ->and($entries[1]->label)->toBe('Disciplines')
        ->and($entries[1]->url)->toBe('/admin/disciplines')
        ->and($entries[1]->permission)->toBe('competition.manage-disciplines');
});
