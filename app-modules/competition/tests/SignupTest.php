<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Competition\Actions\CreateTeam;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\ExpressInterest;
use Konventix\Competition\Actions\JoinTeamByCode;
use Konventix\Competition\Actions\LeaveTeam;
use Konventix\Competition\Actions\MoveTeamMember;
use Konventix\Competition\Actions\RegenerateInviteCode;
use Konventix\Competition\Actions\RemoveTeamMember;
use Konventix\Competition\Actions\RenameTeam;
use Konventix\Competition\Actions\WithdrawInterest;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Gdpr\CompetitionGdprHandler;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\ParticipantProfile;
use Konventix\Compliance\Enums\DeletionMode;
use Konventix\Compliance\Gdpr\SubjectRef;

/**
 * Signup (D-CMP-17, design §5): the gate (§5.1), teams, captains and invite
 * codes (§5.2), and the public interested list (§5.3).
 *
 * The incomplete-roster half of §5 lives in `IncompleteEntrantTest.php`.
 *
 * Helper names are process-global across every module, so the ones only this
 * file uses are prefixed `signupTest`; `competitionEligibleUser()` and
 * `competitionParticipantName()` are in `tests/Helpers.php` because the other
 * file calls them too (.ai/rules/tests.md).
 */
function signupTestCompetition(string $brandId, int $teamSize = 1, ?string $eventId = null): Competition
{
    $eventId ??= (string) Str::ulid();

    $discipline = Discipline::factory()->teamSize($teamSize)->create();

    return Competition::factory()->create([
        'discipline_id' => $discipline->getKey(),
        'event_id' => $eventId,
    ]);
}

it('issues an eight-character invite code without the ambiguous glyphs', function (): void {
    // §5.2: "8 uppercase alphanumerics without ambiguous glyphs (0/O, 1/I)".
    // Read aloud on Discord and typed from a phone; the alphabet is the point.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Die Kellerkinder', $captain);

    expect($team->invite_code)->toHaveLength(8)
        ->and($team->invite_code)->toMatch('/^['.CompetitionTeam::ALPHABET.']{8}$/')
        ->and(CompetitionTeam::ALPHABET)->not->toContain('0')
        ->and(CompetitionTeam::ALPHABET)->not->toContain('O')
        ->and(CompetitionTeam::ALPHABET)->not->toContain('1')
        ->and(CompetitionTeam::ALPHABET)->not->toContain('I');
});

it('makes the person who starts a team its captain', function (): void {
    // §5.2: "exactly one captain per team, assigned at creation". D-CMP-13
    // makes a solo entrant a team of one whose only member is captain.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Solo', $captain);

    expect($team->isCaptain($captain))->toBeTrue()
        ->and($team->captain()?->user_id)->toBe($captain)
        ->and(CompetitionTeamMember::query()->where('competition_team_id', $team->getKey())->count())->toBe(1);
});

it('refuses to start a team for somebody with no confirmed registration', function (): void {
    // The §5.1 gate, on the founding step too: a team founded by somebody who
    // is not coming is a roster the desk has to unpick.
    $brand = $this->withBrand();

    app(CreateTeam::class)->handle($brand->id, (string) Str::ulid(), 'Geister', (string) Str::ulid());
})->throws(CompetitionEntryException::class);

it('lets somebody join with the invite code and refuses an unknown one', function (): void {
    // §5.2: joining by code is "the only self-service way into a team".
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);
    $joiner = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Die Kellerkinder', $captain);

    $member = app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $joiner);

    expect($member->role)->toBe(TeamRole::Member)
        ->and($team->memberUserIds())->toBe([$captain, $joiner]);

    expect(fn () => app(JoinTeamByCode::class)->handle($brand->id, 'ZZZZZZZZ', $joiner))
        ->toThrow(CompetitionEntryException::class);
});

it('forgives the case, the spaces and the glyphs a person cannot have typed', function (): void {
    // A code arrives lower case, with spaces, and with a zero where the
    // alphabet only ever had an O. Widening the alphabet would be the wrong
    // fix; normalising what was typed is the right one.
    expect(CompetitionTeam::normalizeInviteCode(' ab-cd 0f1h '))->toBe('ABCDOFIH');
});

it('refuses a join by somebody with no confirmed registration', function (): void {
    // §5.1, unchanged from D-CMP-05. Joining a team that is entered puts you
    // in the bracket exactly as entering would.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Die Kellerkinder', $captain);

    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, (string) Str::ulid());
})->throws(CompetitionEntryException::class);

it('refuses a join beyond the discipline team size', function (): void {
    // §5.2: "roster size is bounded above by disciplines.team_size: a join
    // beyond it is refused. No substitutes in this slice."
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 2, eventId: $eventId);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $second = competitionEligibleUser($eventId, $brand->id);
    $third = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Zwei Mann', $captain);
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $second);
    app(EnterCompetition::class)->handle($competition, $team->refresh());

    expect(fn () => app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $third))
        ->toThrow(CompetitionEntryException::class);
});

it('rotates captaincy to the longest-standing member when the captain leaves', function (): void {
    // §5.2, LanCore's rule kept: a team whose captain walked off and left
    // nobody able to regenerate the code or withdraw is a support ticket.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);
    $second = competitionEligibleUser($eventId, $brand->id);
    $third = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Rotation', $captain);
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $second);
    app(JoinTeamByCode::class)->handle($brand->id, $team->invite_code, $third);

    app(LeaveTeam::class)->handle($team, $captain);

    expect($team->isCaptain($second))->toBeTrue()
        ->and($team->isCaptain($third))->toBeFalse()
        ->and($team->memberUserIds())->toBe([$second, $third]);
});

it('issues a different invite code on regeneration', function (): void {
    // §5.2: "regenerable by the captain or an organizer" — the remedy when
    // the old one ends up in a public channel.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Leck', $captain);
    $before = $team->invite_code;

    $after = app(RegenerateInviteCode::class)->handle($team);

    expect($after)->not->toBe($before)
        ->and($team->refresh()->invite_code)->toBe($after)
        ->and($after)->toMatch('/^['.CompetitionTeam::ALPHABET.']{8}$/');
});

it('keeps invite codes unique within a brand', function (): void {
    // §5.2: "unique per brand". The index is the constraint; two brands may
    // hand out the same eight characters and neither is wrong.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);
    $other = competitionEligibleUser($eventId, $brand->id);

    $first = app(CreateTeam::class)->handle($brand->id, $eventId, 'Erste', $captain);
    $second = app(CreateTeam::class)->handle($brand->id, $eventId, 'Zweite', $other);

    expect($second->invite_code)->not->toBe($first->invite_code);

    expect(fn () => $second->forceFill(['invite_code' => $first->invite_code])->save())
        ->toThrow(QueryException::class);
});

it('refuses a duplicate registration of the same team at the partial index', function (): void {
    // #297 acceptance criterion 2. The index is the constraint, not a
    // read-then-write check: a double-tapped signup button is exactly the
    // race such a check loses, and a bracket holding the same team twice is
    // a tournament nobody can run.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 1, eventId: $eventId);
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Doppelt', $captain);

    app(EnterCompetition::class)->handle($competition, $team);

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team->refresh()))
        ->toThrow(CompetitionEntryException::class, 'already entered');
});

it('refuses a duplicate registration while the first entry is still incomplete', function (): void {
    // The half the index used to miss. `incomplete` holds a slot (§5.4), so
    // without it in the predicate a short-handed team could enter twice and
    // appear twice once it filled up — and both `max_entrants` and
    // `SignupClosed.entrantCount` would over-count in the meantime.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 5, eventId: $eventId);
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Zu dritt', $captain);
    $first = app(EnterCompetition::class)->handle($competition, $team);

    expect($first->state)->toBe(EntrantState::Incomplete);

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team->refresh()))
        ->toThrow(CompetitionEntryException::class, 'already entered');
});

it('lets a withdrawn team enter again, which is why the index is partial', function (): void {
    // A plain unique on (competition, team) would stop this forever. The
    // partial predicate is what lets a team that pulled out come back.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 1, eventId: $eventId);
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Rückkehr', $captain);
    $entrant = app(EnterCompetition::class)->handle($competition, $team);
    $entrant->forceFill(['state' => EntrantState::Withdrawn])->save();

    $again = app(EnterCompetition::class)->handle($competition, $team->refresh());

    expect($again->state)->toBe(EntrantState::Registered)
        ->and($again->getKey())->not->toBe($entrant->getKey());
});

it('carries the roster and the state on EntrantRegistered', function (): void {
    // §5 / #297: the event gains `member_user_ids` and the state, additively.
    // ULIDs only — the names stay behind ParticipantProfile (ADR-0004).
    Event::fake([EntrantRegistered::class]);

    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 2, eventId: $eventId);
    $captain = competitionEligibleUser($eventId, $brand->id);

    $team = CompetitionTeam::factory()->forEvent($eventId)->create();

    app(EnterCompetition::class)->handle($competition, $team, [$captain]);

    Event::assertDispatched(EntrantRegistered::class, function (EntrantRegistered $event) use ($captain, $team): bool {
        return $event->memberUserIds === [$captain]
            && $event->state === 'incomplete'
            && $event->competitionTeamId === (string) $team->getKey();
    });
});

it('refuses an entry past the participant limit, incomplete rosters included', function (): void {
    // §5.4: "an incomplete entrant holds a slot against the participant
    // limit. Otherwise the fill-up in the next step would have nothing to
    // fill."
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 5, eventId: $eventId);
    $competition->forceFill(['max_entrants' => 1])->save();

    $firstCaptain = competitionEligibleUser($eventId, $brand->id);
    $secondCaptain = competitionEligibleUser($eventId, $brand->id);

    $first = app(CreateTeam::class)->handle($brand->id, $eventId, 'Eins', $firstCaptain);
    $second = app(CreateTeam::class)->handle($brand->id, $eventId, 'Zwei', $secondCaptain);

    $entrant = app(EnterCompetition::class)->handle($competition, $first);
    expect($entrant->state)->toBe(EntrantState::Incomplete);

    expect(fn () => app(EnterCompetition::class)->handle($competition, $second))
        ->toThrow(CompetitionEntryException::class, 'limit');
});

it('puts somebody on the public interested list and takes them off again', function (): void {
    // §5.3: the list is public next to the entered teams so people can find
    // each other; the note is capped at 140 characters.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 5, eventId: $eventId);
    $userId = competitionEligibleUser($eventId, $brand->id);

    $interest = app(ExpressInterest::class)->handle($competition, $userId, 'Spiele AWP, suche Team');

    expect($interest->note)->toBe('Spiele AWP, suche Team')
        ->and(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(1);

    app(WithdrawInterest::class)->handle($competition, $userId);

    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(0);
});

it('edits the existing interest row rather than writing a second one', function (): void {
    // Unique on (competition, user): re-submitting is an edit, not a second
    // row, which is also what the index would insist on.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 5, eventId: $eventId);
    $userId = competitionEligibleUser($eventId, $brand->id);

    $first = app(ExpressInterest::class)->handle($competition, $userId, 'Erste Notiz');
    $second = app(ExpressInterest::class)->handle($competition, $userId, 'Zweite Notiz');

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->note)->toBe('Zweite Notiz')
        ->and(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(1);
});

it('refuses interest from somebody with no confirmed registration', function (): void {
    // §5.3: "registering interest needs the §5.1 gate". A public list anybody
    // could write into would be a recruiting board for people not coming.
    $brand = $this->withBrand();
    $competition = signupTestCompetition($brand->id, teamSize: 5);

    app(ExpressInterest::class)->handle($competition, (string) Str::ulid(), null);
})->throws(CompetitionEntryException::class);

it('retires an interest row when its owner joins an entered roster', function (): void {
    // §5.3: "a user's interest row is deleted when they become a member of an
    // entrant in that competition". Somebody on a roster is not looking.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 5, eventId: $eventId);

    $captain = competitionEligibleUser($eventId, $brand->id);
    $joiner = competitionEligibleUser($eventId, $brand->id);

    $team = app(CreateTeam::class)->handle($brand->id, $eventId, 'Sucht noch', $captain);
    app(EnterCompetition::class)->handle($competition, $team);

    app(ExpressInterest::class)->handle($competition, $joiner, 'Würde mitspielen');
    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(1);

    app(JoinTeamByCode::class)->handle($brand->id, $team->refresh()->invite_code, $joiner);

    expect(CompetitionInterest::forCompetition($brand->id, (string) $competition->getKey()))->toHaveCount(0);
});

it('projects a participant name from Identity and never reads Identity itself', function (): void {
    // #297 acceptance criterion 3, and design §10: "every page shows names
    // from ParticipantProfile, never from Identity". The row can only arrive
    // through the contract event (ADR-0004).
    $userId = (string) Str::ulid();

    competitionParticipantName($userId, 'Sabine S.', 'sabine');

    $profile = ParticipantProfile::query()->whereKey($userId)->first();

    expect($profile?->display_name)->toBe('Sabine S.')
        ->and($profile?->username)->toBe('sabine')
        ->and(ParticipantProfile::displayNamesFor([$userId]))->toBe([$userId => 'Sabine S.']);
});

it('converges on one row when the profile event is replayed', function (): void {
    // Upsert semantics: a replay carries the then-current values, which is
    // what lets the read model be rebuilt from a replay of the events.
    $userId = (string) Str::ulid();

    competitionParticipantName($userId, 'Alt', 'alt');
    competitionParticipantName($userId, 'Neu', 'neu');

    expect(ParticipantProfile::query()->count())->toBe(1)
        ->and(ParticipantProfile::query()->whereKey($userId)->first()?->display_name)->toBe('Neu');
});

it('falls back to a dash rather than printing a ULID at somebody', function (): void {
    // A user with no row yet — registered before the projection existed, or
    // still travelling through the queue. An id on a public page is noise at
    // best and a handle to probe at worst.
    $unknown = (string) Str::ulid();

    expect(ParticipantProfile::displayNamesFor([$unknown]))->toBe([$unknown => '—']);
});

it('gives an organizer the roster tools §5.2 lists', function (): void {
    // Rename, add, remove, move — all behind ManageEntrants at the HTTP
    // layer, all obeying the same §5.1 gate as the self-service join.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $captain = competitionEligibleUser($eventId, $brand->id);
    $player = competitionEligibleUser($eventId, $brand->id);

    $from = app(CreateTeam::class)->handle($brand->id, $eventId, 'Vorher', $captain);
    $to = app(CreateTeam::class)->handle($brand->id, $eventId, 'Nachher', $player);

    app(RenameTeam::class)->handle($from, 'Umbenannt');
    expect($from->refresh()->name)->toBe('Umbenannt');

    app(MoveTeamMember::class)->handle($from, $to, $captain);
    expect($from->memberUserIds())->toBe([])
        ->and($to->memberUserIds())->toBe([$player, $captain]);

    app(RemoveTeamMember::class)->handle($to, $captain);
    expect($to->memberUserIds())->toBe([$player]);
});

it('exports the interested list and scrubs the name on a GDPR deletion', function (): void {
    // The two things this module holds that are not ULIDs: the profile read
    // model and the note somebody wrote on the public list.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 5, eventId: $eventId);
    $userId = competitionEligibleUser($eventId, $brand->id);

    competitionParticipantName($userId, 'Sabine S.', 'sabine');
    app(ExpressInterest::class)->handle($competition, $userId, 'Würde mitspielen');

    $handler = new CompetitionGdprHandler;
    $subject = SubjectRef::forUser($userId);

    expect($handler->exportFor($subject)['interests'])->toHaveCount(1);

    $handler->delete($subject, DeletionMode::Erase);

    expect(ParticipantProfile::query()->whereKey($userId)->exists())->toBeFalse()
        ->and(CompetitionInterest::withoutBrandScope()->where('user_id', $userId)->count())->toBe(0);
});

it('never leaves an entrant behind when a team enters with an explicit roster', function (): void {
    // `EnterCompetition` puts anybody named in the call on the team, so the
    // roster the event carries and the roster the bracket will field are the
    // same list.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $competition = signupTestCompetition($brand->id, teamSize: 2, eventId: $eventId);

    $one = competitionEligibleUser($eventId, $brand->id);
    $two = competitionEligibleUser($eventId, $brand->id);

    $team = CompetitionTeam::factory()->forEvent($eventId)->create();

    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$one, $two]);

    expect($entrant->state)->toBe(EntrantState::Registered)
        ->and($entrant->memberUserIds())->toBe([$one, $two])
        ->and($team->isCaptain($one))->toBeTrue()
        ->and(Entrant::countSlotHolders($brand->id, (string) $competition->getKey()))->toBe(1);
});
