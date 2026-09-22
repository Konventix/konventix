<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Enums\DisciplineKind;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Discipline;

/**
 * The discipline console (design §9, ADR-0024/ADR-0062) on the operator
 * host, and its capability gate.
 *
 * Every row is created **before** the admin-host request: such a request
 * resolves no brand and `BrandScope` hardens brand-owned queries to empty,
 * so a factory row created afterwards would have no brand to be stamped
 * with (.ai/rules/tests.md).
 */
it('403s the discipline console without the manage-disciplines capability', function (): void {
    [, $admin] = competitionOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/disciplines')
        ->assertForbidden();
});

it('lists disciplines with how many competitions run them, and filters by kind', function (): void {
    $this->withBrand();
    [, $admin] = competitionOperator();

    $quiz = Discipline::factory()->create(['name' => 'Pub quiz', 'slug' => 'pub-quiz']);
    $game = Discipline::factory()->videoGame()->create(['name' => 'Rocket Cars', 'slug' => 'rocket-cars']);

    Competition::factory()->count(2)->create(['discipline_id' => $game->getKey()]);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/disciplines')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/disciplines/Index')
            ->has('disciplines.data', 2)
            ->where('disciplines.data.0.name', 'Pub quiz')
            ->where('disciplines.data.0.competitions', 0)
            ->where('disciplines.data.1.name', 'Rocket Cars')
            ->where('disciplines.data.1.competitions', 2));

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/disciplines?kind=quiz')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('disciplines.data', 1)
            ->where('disciplines.data.0.slug', 'pub-quiz')
            ->where('filters.kind', 'quiz'));

    expect($quiz->refresh()->kind)->toBe(DisciplineKind::Quiz);
});

it('creates a discipline with its contest defaults under the chosen brand', function (): void {
    $brand = $this->withBrand();
    [, $admin] = competitionOperator();

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/disciplines', [
            'brand_id' => $brand->getKey(),
            'kind' => 'video_game',
            'name' => 'Rocket Cars',
            'slug' => 'rocket-cars',
            'teamSize' => 3,
            'avgMatchMinutes' => 20,
            'allowDraws' => false,
            'gameId' => null,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $discipline = Discipline::withoutBrandScope()->where('slug', 'rocket-cars')->sole();

    expect($discipline->brand_id)->toBe($brand->getKey())
        ->and($discipline->kind)->toBe(DisciplineKind::VideoGame)
        ->and($discipline->team_size)->toBe(3)
        ->and($discipline->avg_match_minutes)->toBe(20)
        ->and($discipline->allow_draws)->toBeFalse()
        ->and($discipline->game_id)->toBeNull();
});

it('refuses a slug the brand already uses', function (): void {
    $brand = $this->withBrand();
    [, $admin] = competitionOperator();

    Discipline::factory()->create(['slug' => 'chess']);

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/disciplines', [
            'brand_id' => $brand->getKey(),
            'kind' => 'tabletop',
            'name' => 'Chess again',
            'slug' => 'chess',
            'teamSize' => 1,
            'avgMatchMinutes' => 30,
            'allowDraws' => true,
        ])
        ->assertSessionHasErrors('slug');

    expect(Discipline::withoutBrandScope()->where('slug', 'chess')->count())->toBe(1);
});

it('updates a discipline and freezes its team size once a competition runs it', function (): void {
    $this->withBrand();
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create([
        'name' => 'Chess',
        'slug' => 'chess',
        'team_size' => 1,
        'avg_match_minutes' => 30,
    ]);

    $this->actingAs($admin)
        ->put('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey(), [
            'kind' => 'tabletop',
            'name' => 'Chess (blitz)',
            'slug' => 'chess',
            'teamSize' => 2,
            'avgMatchMinutes' => 10,
            'allowDraws' => true,
            'gameId' => null,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey().'/edit');

    expect($discipline->refresh()->name)->toBe('Chess (blitz)')
        ->and($discipline->team_size)->toBe(2)
        ->and($discipline->allow_draws)->toBeTrue();
});

it('refuses a team-size change once competitions run the discipline', function (): void {
    // The number decides which rosters count as incomplete (§5.4); moving it
    // mid-event would re-classify teams that were complete when they entered.
    $this->withBrand();
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create(['slug' => 'rocket-cars', 'team_size' => 2]);
    Competition::factory()->create(['discipline_id' => $discipline->getKey()]);

    $this->actingAs($admin)
        ->put('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey(), [
            'kind' => 'video_game',
            'name' => 'Rocket Cars',
            'slug' => 'rocket-cars',
            'teamSize' => 5,
            'avgMatchMinutes' => 20,
            'allowDraws' => false,
            'gameId' => null,
        ])
        ->assertSessionHasErrors('teamSize');

    expect($discipline->refresh()->team_size)->toBe(2);
});

it('renders the discipline list and editor pages', function (): void {
    // A page added this session needs `npm run build` first: Inertia resolves
    // it through the Vite manifest and a missing entry looks like a 500
    // (.ai/rules/tests.md).
    $this->withBrand();
    [, $admin] = competitionOperator();

    $discipline = Discipline::factory()->create(['name' => 'Pinball', 'slug' => 'pinball']);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/disciplines')
        ->assertOk()
        ->assertSee('Pinball');

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/disciplines/'.$discipline->getKey().'/edit')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/disciplines/Edit')
            ->where('discipline.id', (string) $discipline->getKey())
            ->where('discipline.slug', 'pinball')
            ->where('competitions', 0));
});
