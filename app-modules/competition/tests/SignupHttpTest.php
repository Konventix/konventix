<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\WithdrawalReason;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\Discipline;
use Konventix\Identity\Models\User;

/**
 * The HTTP layer of signup (design §5, §10): the capability gate on the
 * console's nine entrant endpoints, and the ownership rules on the
 * participant pages.
 *
 * `SignupTest` and `IncompleteEntrantTest` drive the Actions directly, which
 * is the right shape for the rules — but it leaves the controllers' own
 * decisions unverified, and those decisions are the ones nobody can confirm
 * by reading: a `Gate::authorize()` that names the wrong capability, a
 * missing `abort_unless`, a 403 where the codebase promises a 404, an invite
 * code sent to somebody who is not the captain.
 *
 * `Identity\Models\User` appears in this file, as it does in `CheckInTest`,
 * because `actingAs()` needs a real account row. **No file under `src/`
 * imports it** — names come from `ParticipantProfile`, this module's own read
 * model (§10, #297), and `GameOpsBoundaryTest` fails the build if that ever
 * changes.
 *
 * Helper names are process-global across every module (.ai/rules/tests.md);
 * the ones only this file uses are prefixed `signupHttp`.
 */
function signupHttpCompetition(int $teamSize, string $eventId): Competition
{
    return Competition::factory()->create([
        'discipline_id' => Discipline::factory()->teamSize($teamSize)->create()->getKey(),
        'event_id' => $eventId,
    ]);
}

/**
 * A member of the brand with a confirmed registration for the event — the
 * §5.1 gate, arriving through Registration's contract events as always.
 */
function signupHttpPlayer(string $eventId, string $brandId): User
{
    $user = User::factory()->create();

    competitionEligibleUser($eventId, $brandId, (string) $user->getKey());

    return $user;
}

/*
 * ---------------------------------------------------------------------------
 * The console: `ManageEntrants` on all nine endpoints (§5.2, §5.4, §5.5)
 * ---------------------------------------------------------------------------
 */

it('403s every entrant endpoint without the manage-entrants capability', function (): void {
    // Nine endpoints, one capability. Every method opens with its own
    // `Gate::authorize()` because the admin host has no brand to gate on
    // (ADR-0029) — which also means a method that forgot the line would be
    // wide open to any console account, and nothing else would notice.
    //
    // Random ULIDs are enough: the authorize call is the first statement in
    // every one of the nine, before validation and before any lookup.
    [, $admin] = competitionOperator(['platform.access-admin', 'competition.view-competitions']);

    $entrant = (string) Str::ulid();
    $team = (string) Str::ulid();
    $host = 'http://admin.tournaments.test';

    $endpoints = [
        ['post', "{$host}/admin/entrants/{$entrant}/fill"],
        ['post', "{$host}/admin/entrants/{$entrant}/decline"],
        ['post', "{$host}/admin/entrants/{$entrant}/withdraw"],
        ['post', "{$host}/admin/entrants/{$entrant}/disqualify"],
        ['put', "{$host}/admin/competition-teams/{$team}"],
        ['post', "{$host}/admin/competition-teams/{$team}/invite-code"],
        ['post', "{$host}/admin/competition-teams/{$team}/members"],
        ['delete', "{$host}/admin/competition-teams/{$team}/members"],
        ['post', "{$host}/admin/competition-teams/{$team}/members/move"],
    ];

    expect($endpoints)->toHaveCount(9);

    foreach ($endpoints as [$method, $url]) {
        $this->actingAs($admin)->{$method}($url)->assertForbidden();
    }
});

it('403s the entrants panel without the view capability', function (): void {
    // The panel reads, so it asks for `ViewCompetitions` rather than
    // `ManageEntrants` — an organizer who may look at the field is not
    // thereby allowed to change it.
    [, $admin] = competitionOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions/'.Str::ulid().'/entrants')
        ->assertForbidden();
});

it('shows the organizer every incomplete roster and the interested list beside it', function (): void {
    // §5.4's prompt. Every row is created BEFORE the admin-host request: such
    // a request resolves no brand and `BrandScope` hardens brand-owned
    // queries to empty (.ai/rules/tests.md).
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 5, eventId: $eventId);

    $captain = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($captain, 'Käpt\'n K.', 'kaeptn');

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Zu dritt', $captain);
    app(EnterCompetition::class)->handle($competition, $team);

    $interested = competitionEligibleUser($eventId, $brand->id);
    competitionParticipantName($interested, 'Vierter V.', 'vierter');
    app(ExpressInterest::class)->handle($competition, $interested, 'Spiele AWP');

    [, $admin] = competitionOperator();

    $this->actingAs($admin)
        ->get('http://admin.tournaments.test/admin/competitions/'.$competition->getKey().'/entrants')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/competitions/Entrants')
            ->where('competition.teamSize', 5)
            ->where('entrants.0.state', 'incomplete')
            ->where('entrants.0.missing', 4)
            // Names come from ParticipantProfile, never from Identity.
            ->where('entrants.0.members.0.displayName', 'Käpt\'n K.')
            ->where('interested.0.displayName', 'Vierter V.')
            ->where('interested.0.note', 'Spiele AWP'));
});

it('fills and declines an incomplete roster from the console', function (): void {
    // The two answers to §5.4's prompt, through the endpoints the buttons
    // post to rather than through the Actions they resolve.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 2, eventId: $eventId);

    $first = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Wird gefüllt', competitionEligibleUser($eventId, $brand->id),
    );
    $second = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Wird abgelehnt', competitionEligibleUser($eventId, $brand->id),
    );

    $filled = app(EnterCompetition::class)->handle($competition, $first);
    $declined = app(EnterCompetition::class)->handle($competition, $second);

    $spare = competitionEligibleUser($eventId, $brand->id);

    // `competitionOperator()`'s default set does not carry `ManageEntrants`;
    // the endpoints under test are the reason it has to be named.
    [, $admin] = competitionOperator([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.manage-entrants',
    ]);

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/entrants/'.$filled->getKey().'/fill', [
            'userIds' => [$spare],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/entrants/'.$declined->getKey().'/decline')
        ->assertSessionHasNoErrors();

    expect($filled->refresh()->state)->toBe(EntrantState::Registered)
        ->and($declined->refresh()->state)->toBe(EntrantState::Withdrawn)
        ->and($declined->withdrawal_reason)->toBe(WithdrawalReason::IncompleteRoster);
});

it('answers a refused console move with a field error rather than a 500', function (): void {
    // An organizer picked somebody who holds no confirmed registration. That
    // is something to be told in the panel they are standing in, which is why
    // `EntrantAdminController::run()` translates the Action's refusal into a
    // validation error on `entrant`.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 2, eventId: $eventId);

    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Kurz', competitionEligibleUser($eventId, $brand->id),
    );
    $entrant = app(EnterCompetition::class)->handle($competition, $team);

    [, $admin] = competitionOperator([
        'platform.access-admin',
        'competition.view-competitions',
        'competition.manage-entrants',
    ]);

    $this->actingAs($admin)
        ->post('http://admin.tournaments.test/admin/entrants/'.$entrant->getKey().'/fill', [
            'userIds' => [(string) Str::ulid()],
        ])
        ->assertSessionHasErrors('entrant');

    expect($entrant->refresh()->state)->toBe(EntrantState::Incomplete);
});

/*
 * ---------------------------------------------------------------------------
 * The participant pages: ownership, captaincy, and the code (§5.2, §10)
 * ---------------------------------------------------------------------------
 */

it('shows the invite code to the captain and to nobody else on the team', function (): void {
    // §5.2: the code is a shared secret, and only the captain is accountable
    // for where it ends up. The server sends `null` to everybody else, so the
    // page never holds a code it must remember not to render.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $captain = signupHttpPlayer($eventId, $brand->id);
    $member = signupHttpPlayer($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Die Kellerkinder', (string) $captain->getKey(),
    );
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, (string) $member->getKey());

    $this->actingAs($captain)
        ->get('/my/tournaments')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('competition/my/Tournaments')
            ->where('teams.0.isCaptain', true)
            ->where('teams.0.inviteCode', $team->invite_code));

    $this->actingAs($member)
        ->get('/my/tournaments')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teams.0.isCaptain', false)
            ->where('teams.0.inviteCode', null));
});

it('answers a team you are not on with a 404, never a 403', function (): void {
    // A 403 on a ULID confirms that the ULID names something. Everything you
    // do not own is simply not there.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $captain = signupHttpPlayer($eventId, $brand->id);
    $stranger = signupHttpPlayer($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Nicht deins', (string) $captain->getKey(),
    );

    $this->actingAs($stranger)
        ->delete('/my/tournaments/teams/'.$team->getKey().'/members/me')
        ->assertNotFound();

    $this->actingAs($stranger)
        ->post('/my/tournaments/teams/'.$team->getKey().'/invite-code')
        ->assertNotFound();

    // A team that does not exist at all answers the same way.
    $this->actingAs($stranger)
        ->delete('/my/tournaments/teams/'.Str::ulid().'/members/me')
        ->assertNotFound();
});

it('lets only the captain regenerate the code, and a member gets a 403', function (): void {
    // The member IS on the team, so the answer is no longer "that does not
    // exist" — it is "not you". §5.2 gives the code to the captain and to an
    // organizer, and a member locking their own team out of Discord is
    // exactly what this refuses.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $captain = signupHttpPlayer($eventId, $brand->id);
    $member = signupHttpPlayer($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Ein Code', (string) $captain->getKey(),
    );
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, (string) $member->getKey());

    $before = $team->refresh()->invite_code;

    $this->actingAs($member)
        ->post('/my/tournaments/teams/'.$team->getKey().'/invite-code')
        ->assertForbidden();

    expect($team->refresh()->invite_code)->toBe($before);

    $this->actingAs($captain)
        ->post('/my/tournaments/teams/'.$team->getKey().'/invite-code')
        ->assertSessionHasNoErrors();

    expect($team->refresh()->invite_code)->not->toBe($before);
});

it('lets only the captain withdraw the entry', function (): void {
    // §5.5: "a captain may withdraw the team until Running". A member is a
    // 403 — they are on the team, so the entry is not hidden from them — and
    // somebody who is not on it at all gets the 404.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 2, eventId: $eventId);

    $captain = signupHttpPlayer($eventId, $brand->id);
    $member = signupHttpPlayer($eventId, $brand->id);
    $stranger = signupHttpPlayer($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Zieht zurück', (string) $captain->getKey(),
    );
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, (string) $member->getKey());

    $entrant = app(EnterCompetition::class)->handle($competition, $team->refresh());

    $this->actingAs($stranger)
        ->delete('/my/tournaments/entries/'.$entrant->getKey())
        ->assertNotFound();

    $this->actingAs($member)
        ->delete('/my/tournaments/entries/'.$entrant->getKey())
        ->assertForbidden();

    expect($entrant->refresh()->state)->toBe(EntrantState::Registered);

    $this->actingAs($captain)
        ->delete('/my/tournaments/entries/'.$entrant->getKey())
        ->assertSessionHasNoErrors();

    expect($entrant->refresh()->state)->toBe(EntrantState::Withdrawn)
        ->and($entrant->withdrawal_reason)->toBe(WithdrawalReason::CaptainWithdrew);
});

it('refuses every bad invite code with one sentence, whatever was wrong', function (): void {
    // A distinct "that code exists but you are not registered for this event"
    // would turn the form into an oracle for guessing codes. Three different
    // refusals, one message.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 1, eventId: $eventId);

    $captain = signupHttpPlayer($eventId, $brand->id);
    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Voll', (string) $captain->getKey(),
    );
    app(EnterCompetition::class)->handle($competition, $team);

    $eligible = signupHttpPlayer($eventId, $brand->id);
    $unregistered = User::factory()->create();

    $messages = [];

    // 1. A code nobody holds.
    $this->actingAs($eligible)
        ->post('/my/tournaments/join', ['code' => 'ZZZZZZZZ'])
        ->assertSessionHasErrors('code');
    $messages[] = session('errors')->first('code');

    // 2. A real code, but the roster is already at the team size.
    $this->actingAs($eligible)
        ->post('/my/tournaments/join', ['code' => $team->refresh()->invite_code])
        ->assertSessionHasErrors('code');
    $messages[] = session('errors')->first('code');

    // 3. A real code, but no confirmed registration for the event.
    $this->actingAs($unregistered)
        ->post('/my/tournaments/join', ['code' => $team->refresh()->invite_code])
        ->assertSessionHasErrors('code');
    $messages[] = session('errors')->first('code');

    expect(array_unique($messages))->toHaveCount(1);
});

it('joins a team through the form, spaces and lower case and all', function (): void {
    // The happy path, and the normalising with it: a code pasted out of a
    // chat window arrives lower case and spaced.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $captain = signupHttpPlayer($eventId, $brand->id);
    $joiner = signupHttpPlayer($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle(
        $brand->id, $eventId, 'Kommt rein', (string) $captain->getKey(),
    );

    $typed = strtolower(substr($team->invite_code, 0, 4).' '.substr($team->invite_code, 4));

    $this->actingAs($joiner)
        ->post('/my/tournaments/join', ['code' => $typed])
        ->assertRedirect(route('competition.my.tournaments'));

    expect($team->refresh()->memberUserIds())->toBe([
        (string) $captain->getKey(),
        (string) $joiner->getKey(),
    ]);
});

it('takes interest in a competition and withdraws it again over HTTP', function (): void {
    // §5.3's two endpoints. They hang off the competition rather than a page
    // of their own, because the list they write to renders on the competition
    // page next to the entered teams.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 5, eventId: $eventId);
    $player = signupHttpPlayer($eventId, $brand->id);

    $this->actingAs($player)
        ->post('/tournaments/'.$competition->getKey().'/interest', ['note' => 'Spiele AWP'])
        ->assertSessionHasNoErrors();

    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(1);

    $this->actingAs($player)
        ->delete('/tournaments/'.$competition->getKey().'/interest')
        ->assertSessionHasNoErrors();

    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(0);
});

it('refuses interest from somebody with no confirmed registration, as a field error', function (): void {
    // §5.1 again, this time at the HTTP edge: the gate lives in the Action, so
    // the controller only has to turn the refusal into something the form can
    // show — the note is the only field it has.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $competition = signupHttpCompetition(teamSize: 5, eventId: (string) Str::ulid());

    $this->actingAs(User::factory()->create())
        ->post('/tournaments/'.$competition->getKey().'/interest', ['note' => null])
        ->assertSessionHasErrors('note');

    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(0);
});

it('caps the interest note at the 140 characters §5.3 allows', function (): void {
    // A line, not an essay — and the column agrees, so the validator is what
    // keeps a 141st character from becoming a 500.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $competition = signupHttpCompetition(teamSize: 5, eventId: $eventId);
    $player = signupHttpPlayer($eventId, $brand->id);

    $this->actingAs($player)
        ->post('/tournaments/'.$competition->getKey().'/interest', ['note' => str_repeat('a', 141)])
        ->assertSessionHasErrors('note');

    $this->actingAs($player)
        ->post('/tournaments/'.$competition->getKey().'/interest', ['note' => str_repeat('a', 140)])
        ->assertSessionHasNoErrors();
});

it('answers a competition of another brand with a 404', function (): void {
    // The brand host resolves a brand and `BrandScope` does the rest: a
    // competition of somebody else's brand is simply not there, and the
    // reader learns nothing about whether it exists.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $player = signupHttpPlayer($eventId, $brand->id);

    $this->actingAs($player)
        ->post('/tournaments/'.Str::ulid().'/interest', ['note' => null])
        ->assertNotFound();
});

it('sends the teams of the signed-in person and nobody else', function (): void {
    // "Meine Turniere" is a list of what is yours. Another person's team is
    // absent, not hidden behind a flag the page has to remember to honour.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = (string) Str::ulid();
    $mine = signupHttpPlayer($eventId, $brand->id);
    $theirs = signupHttpPlayer($eventId, $brand->id);

    app(CreateTeam::class)->handle($brand->id, $eventId, 'Meins', (string) $mine->getKey());
    app(CreateTeam::class)->handle($brand->id, $eventId, 'Ihres', (string) $theirs->getKey());

    $this->actingAs($mine)
        ->get('/my/tournaments')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('teams', 1)
            ->where('teams.0.name', 'Meins'));
});

it('requires an account on every participant route', function (): void {
    // `auth` and nothing else — there is no capability for reading back the
    // teams you are on, and none is missing.
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $this->get('/my/tournaments')->assertRedirect(route('login'));
    $this->get('/my/tournaments/join')->assertRedirect(route('login'));
});

it('404s the participant pages for a brand that runs no tournaments', function (): void {
    // Competition is Pennant-gated (ADR-0003): a brand that never switched it
    // on pays for none of it, and `ensure-module:competition` answers 404
    // rather than rendering an empty page.
    $this->withBrand();

    $player = User::factory()->create();

    $this->actingAs($player)->get('/my/tournaments')->assertNotFound();
    $this->actingAs($player)->get('/my/tournaments/join')->assertNotFound();
    $this->actingAs($player)->post('/tournaments/'.Str::ulid().'/interest')->assertNotFound();
});
