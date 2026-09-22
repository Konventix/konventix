<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\Permission;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Identity\Models\User;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * Every competition console controller keeps to the admin host's own brands
 * (`ScopesToOperator`, ADR-0029).
 *
 * The console escapes `BrandScope` on every query because the scope hardens
 * to zero rows on the admin host, so without this nothing stopped an
 * operator Y console account that knew one of operator X's ULIDs from reading
 * or rewriting X's tournaments — the capability had been checked against Y's
 * team, which says nothing about X's rows.
 *
 * Each test builds operator X's world first (brand, discipline, competition,
 * team, entrant — all BEFORE any admin-host request, .ai/rules/tests.md),
 * then a second operator Y holding **every** competition capability on its
 * own host, and walks every endpoint of one controller from Y's host. Every
 * one must answer 404, never 403 (a 403 would confirm the row exists) and
 * never a redirect (a redirect would mean the write ran or got as far as
 * validation). Payloads are valid on purpose, for the same reason: a 404
 * that only happened because validation failed first would prove nothing.
 *
 * The home operator's own host reaching the same pages is the control that
 * the 404 is the scope and not a broken route.
 */

/**
 * Operator X's tournament, and operator Y's console account. `$brand` comes
 * from `$this->withBrand()` in the test itself: a plain helper cannot call a
 * protected TestCase method (.ai/rules/tests.md).
 *
 * @return array{brandId: string, eventId: string, discipline: Discipline, competition: Competition, team: CompetitionTeam, entrant: Entrant, stranger: User, home: User}
 */
function consoleScopeWorld(Brand $brand): array
{
    $eventId = competitionConsoleEvent($brand);

    $discipline = Discipline::factory()->create(['name' => 'Rocket Cars', 'team_size' => 1]);

    $competition = Competition::factory()
        ->forEvent($eventId)
        ->status(CompetitionStatus::Published)
        ->create(['name' => 'Main Cup', 'discipline_id' => $discipline->getKey()]);

    $team = CompetitionTeam::factory()->forEvent($eventId)->create(['name' => 'Home Team']);

    $entrant = Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'competition_team_id' => $team->getKey(),
    ]);

    $everything = ['platform.access-admin', ...array_column(Permission::cases(), 'value')];

    // Home first: `competitionOperator()` without an operator takes the
    // installation's first one, which is the one the brand above hangs on.
    [, $home] = competitionOperator($everything);

    $elsewhere = Operator::factory()->create();
    [$elsewhere, $stranger] = competitionOperator($everything, operator: $elsewhere, host: 'admin.elsewhere.test');

    expect($elsewhere->getKey())->not->toBe($brand->operator_id);

    return [
        'brandId' => (string) $brand->getKey(),
        'eventId' => $eventId,
        'discipline' => $discipline,
        'competition' => $competition,
        'team' => $team,
        'entrant' => $entrant,
        'stranger' => $stranger,
        'home' => $home,
    ];
}

/**
 * A valid competition form for the world's event and discipline, so the only
 * thing that can refuse it is the operator check.
 *
 * @param  array{eventId: string, discipline: Discipline}  $world
 * @return array<string, mixed>
 */
function consoleScopeCompetitionPayload(array $world, string $slug): array
{
    return [
        'eventId' => $world['eventId'],
        'disciplineId' => (string) $world['discipline']->getKey(),
        'name' => ucfirst($slug),
        'slug' => $slug,
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
    ];
}

/**
 * Walks `$endpoints` from operator Y's host and expects a 404 for each.
 *
 * @param  list<array{0: string, 1: string, 2?: array<string, mixed>}>  $endpoints  method, path below /admin, payload
 */
function consoleScopeExpectNotFound(mixed $test, User $stranger, array $endpoints): void
{
    foreach ($endpoints as $endpoint) {
        [$method, $path] = $endpoint;
        $payload = $endpoint[2] ?? [];

        $response = $test->actingAs($stranger)->{$method}('http://admin.elsewhere.test/admin'.$path, $payload);

        expect($response->status())->toBe(404, strtoupper($method).' '.$path.' answered '.$response->status());
    }
}

it('hides another operator\'s disciplines from the discipline console', function (): void {
    $world = consoleScopeWorld($this->withBrand());
    $id = $world['discipline']->getKey();

    consoleScopeExpectNotFound($this, $world['stranger'], [
        ['get', "/disciplines/{$id}/edit"],
        ['put', "/disciplines/{$id}", [
            'kind' => 'video_game', 'name' => 'Stolen', 'slug' => 'stolen', 'teamSize' => 1,
            'avgMatchMinutes' => 20, 'allowDraws' => false, 'gameId' => null,
        ]],
        ['post', '/disciplines', [
            'brand_id' => $world['brandId'], 'kind' => 'quiz', 'name' => 'Planted', 'slug' => 'planted',
            'teamSize' => 1, 'avgMatchMinutes' => 20, 'allowDraws' => false, 'gameId' => null,
        ]],
    ]);

    // The list is narrowed rather than refused: Y sees its own (none).
    $this->actingAs($world['stranger'])
        ->get('http://admin.elsewhere.test/admin/disciplines')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('disciplines.data', 0)->has('brands', 0));

    $this->actingAs($world['home'])
        ->get('http://admin.tournaments.test/admin/disciplines/'.$id.'/edit')
        ->assertOk();

    expect($world['discipline']->refresh()->name)->toBe('Rocket Cars')
        ->and(Discipline::withoutBrandScope()->where('slug', 'planted')->exists())->toBeFalse();
});

it('hides another operator\'s competitions from the competition console', function (): void {
    $world = consoleScopeWorld($this->withBrand());
    $id = $world['competition']->getKey();

    consoleScopeExpectNotFound($this, $world['stranger'], [
        ['get', "/competitions/{$id}/edit"],
        ['put', "/competitions/{$id}", consoleScopeCompetitionPayload($world, 'stolen')],
        ['post', "/competitions/{$id}/transition", ['status' => 'cancelled']],
        ['post', '/competitions', ['brand_id' => $world['brandId'], ...consoleScopeCompetitionPayload($world, 'planted')]],
    ]);

    $this->actingAs($world['stranger'])
        ->get('http://admin.elsewhere.test/admin/competitions')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('competitions.data', 0)
            ->has('brands', 0)
            ->has('events', 0));

    $this->actingAs($world['home'])
        ->get('http://admin.tournaments.test/admin/competitions/'.$id.'/edit')
        ->assertOk();

    expect($world['competition']->refresh()->name)->toBe('Main Cup')
        ->and($world['competition']->status)->toBe(CompetitionStatus::Published)
        ->and(Competition::withoutBrandScope()->where('slug', 'planted')->exists())->toBeFalse();
});

it('hides another operator\'s field from the entrant console', function (): void {
    $world = consoleScopeWorld($this->withBrand());
    $competition = $world['competition']->getKey();
    $entrant = $world['entrant']->getKey();
    $team = $world['team']->getKey();
    $somebody = (string) Str::ulid();

    consoleScopeExpectNotFound($this, $world['stranger'], [
        ['get', "/competitions/{$competition}/entrants"],
        ['post', "/entrants/{$entrant}/fill", ['userIds' => [$somebody]]],
        ['post', "/entrants/{$entrant}/decline"],
        ['post', "/entrants/{$entrant}/withdraw"],
        ['post', "/entrants/{$entrant}/disqualify"],
        ['put', "/competition-teams/{$team}", ['name' => 'Stolen']],
        ['post', "/competition-teams/{$team}/invite-code"],
        ['post', "/competition-teams/{$team}/members", ['userId' => $somebody]],
        ['delete', "/competition-teams/{$team}/members", ['userId' => $somebody]],
        ['post', "/competition-teams/{$team}/members/move", ['userId' => $somebody, 'toTeamId' => $team]],
    ]);

    $this->actingAs($world['home'])
        ->get('http://admin.tournaments.test/admin/competitions/'.$competition.'/entrants')
        ->assertOk();

    expect($world['team']->refresh()->name)->toBe('Home Team')
        ->and($world['entrant']->refresh()->state)->toBe(EntrantState::Registered);
});

it('hides another operator\'s field from the check-in desk', function (): void {
    $world = consoleScopeWorld($this->withBrand());
    $entrant = $world['entrant']->getKey();

    consoleScopeExpectNotFound($this, $world['stranger'], [
        ['get', '/competitions/'.$world['competition']->getKey().'/check-in'],
        ['post', "/entrants/{$entrant}/check-in"],
        ['delete', "/entrants/{$entrant}/check-in"],
    ]);

    $this->actingAs($world['home'])
        ->get('http://admin.tournaments.test/admin/competitions/'.$world['competition']->getKey().'/check-in')
        ->assertOk();

    expect($world['entrant']->refresh()->state)->toBe(EntrantState::Registered);
});
