<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionInterest;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchResultReport;
use Konventix\Event\Events\EventCreated;
use Konventix\Event\Events\EventPublished;
use Konventix\Identity\Models\User;

/**
 * What every participant write asks before it acts, beyond the Action's own
 * guards (final review fix wave B).
 *
 * Item 3: a participant write is keyed by a competition's or an entrant's id
 * and never passes through `/events/{slug}`, so it asks the tournament pages'
 * rule itself — the competition is not a draft and its event is public.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */
function writeGuardsTestMember(Entrant $entrant, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();

    $member = new CompetitionTeamMember([
        'competition_team_id' => $entrant->competition_team_id,
        'user_id' => (string) $user->getKey(),
        'role' => $role,
    ]);
    $member->forceFill(['brand_id' => $entrant->brand_id])->save();

    return $user;
}

it('answers every participant write by id with a 404 while the event is a draft, and lets each through once it is published', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    // Created and never published: a draft, whose tournament pages 404.
    $eventId = (string) Str::ulid();
    event(new EventCreated(
        eventId: $eventId,
        brandId: (string) $brand->getKey(),
        slug: 'sxlan-draft',
        name: 'SXLan Draft',
        startsAt: '2027-11-20 10:00:00',
        endsAt: '2027-11-22 18:00:00',
        timezone: 'Europe/Berlin',
        predecessorEventId: null,
    ));

    // A published-and-closed competition (`SignupClosed`, visible) with a
    // drawn bracket whose sides may report, and which takes proofs.
    [$competition, [$a]] = competitionFieldWithRosters(2, [
        'event_id' => $eventId,
        'result_submission_mode' => ResultSubmissionMode::Participants,
        'proof_policy' => ProofPolicy::Optional,
    ]);
    $match = competitionMatchAt(competitionGeneratedStage($competition), 1, 1);

    // Three people on $a's team, so no one account spends more than seven of
    // the ten writes a minute the participant budget allows.
    $reporter = writeGuardsTestMember($a);
    $member = writeGuardsTestMember($a);
    $captain = writeGuardsTestMember($a, TeamRole::Captain);

    $base = '/tournaments/'.$competition->id;
    $entry = '/my/tournaments/entries/'.$a->id;

    $this->actingAs($reporter)->post($base.'/matches/'.$match->id.'/report', ['scores' => [1 => 16, 2 => 9]])->assertNotFound();
    $this->actingAs($reporter)->post($base.'/interest', ['note' => null])->assertNotFound();
    $this->actingAs($captain)->post($base.'/entries', ['teamId' => $a->competition_team_id])->assertNotFound();
    $this->actingAs($captain)->post($base.'/score-entries', ['value' => null])->assertNotFound();
    // `postJson()`, not a lasting `withHeader()`: the redirects below must
    // stay redirects with session errors.
    $this->actingAs($captain)->postJson($base.'/proofs', ['entrant_id' => $a->id])->assertNotFound();
    $this->actingAs($member)->delete($entry)->assertNotFound();
    $this->actingAs($member)->post($entry.'/check-in')->assertNotFound();

    expect(MatchResultReport::query()->where('competition_match_id', $match->id)->exists())->toBeFalse()
        ->and(CompetitionInterest::query()->where('competition_id', $competition->id)->exists())->toBeFalse();

    event(new EventPublished(eventId: $eventId, brandId: (string) $brand->getKey(), publishedAt: '2026-10-01T10:00:00+00:00'));

    // The same writes now reach their controllers and Actions: each answers
    // with its own refusal, or succeeds, but none is a 404 any more.
    $this->actingAs($reporter)->post($base.'/matches/'.$match->id.'/report', ['scores' => [1 => 16, 2 => 9]])->assertSessionHasNoErrors();
    // No confirmed registration: §5.1's refusal, on the note.
    $this->actingAs($reporter)->post($base.'/interest', ['note' => null])->assertSessionHasErrors('note');
    // The bare-ULID captain `competitionFieldWithRosters()` made holds no
    // registration: the roster check's refusal, on the team.
    $this->actingAs($captain)->post($base.'/entries', ['teamId' => $a->competition_team_id])->assertSessionHasErrors('teamId');
    // A value is required: the form's own validation.
    $this->actingAs($captain)->post($base.'/score-entries', ['value' => null])->assertSessionHasErrors('value');
    // No file: validation again, a 422 rather than a 404.
    $this->actingAs($captain)->postJson($base.'/proofs', ['entrant_id' => $a->id])->assertUnprocessable();
    // A member, not the captain: §5.5's 403.
    $this->actingAs($member)->delete($entry)->assertForbidden();
    // No self-service window: the Action's refusal, on the entry.
    $this->actingAs($member)->post($entry.'/check-in')->assertSessionHasErrors('entrant');

    expect(MatchResultReport::query()->where('competition_match_id', $match->id)->count())->toBe(1);
});

/*
 * ---------------------------------------------------------------------------
 * Item 4, part 2, M3: the proof upload refuses what could never carry a proof
 * ---------------------------------------------------------------------------
 */

it('refuses a proof for a finished or cancelled competition and from a team that no longer plays, before a byte is stored', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    Storage::fake('media');

    [$competition, [$a]] = competitionFieldWithRosters(1, [
        'proof_policy' => ProofPolicy::Optional,
        'event_id' => competitionPublicEvent($brand->id),
    ]);
    $player = writeGuardsTestMember($a);

    $upload = fn () => $this->actingAs($player)
        ->withHeader('Accept', 'application/json')
        ->post('/tournaments/'.$competition->id.'/proofs', [
            'entrant_id' => $a->id,
            'file' => UploadedFile::fake()->image('scoreboard.png'),
        ]);

    foreach ([CompetitionStatus::Finished, CompetitionStatus::Cancelled] as $over) {
        $competition->forceFill(['status' => $over])->save();

        $upload()->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Dieses Turnier ist vorbei und nimmt keine Beweisbilder mehr an.']);
    }

    $competition->forceFill(['status' => CompetitionStatus::SignupClosed])->save();

    foreach ([EntrantState::Withdrawn, EntrantState::Disqualified] as $gone) {
        $a->forceFill(['state' => $gone])->save();

        $upload()->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Euer Team ist nicht mehr im Turnier und kann keine Ergebnisse mehr einreichen.']);
    }

    // A draft competition is not there at all (the item 3 rule).
    $a->forceFill(['state' => EntrantState::Registered])->save();
    $competition->forceFill(['status' => CompetitionStatus::Draft])->save();

    $upload()->assertNotFound();

    expect(Storage::disk('media')->allFiles())->toBe([]);

    // The control: the same person, the same file, the competition back at
    // `SignupClosed` and the team playing — stored. Six requests above and
    // this one stay inside the budget of ten a minute.
    $competition->forceFill(['status' => CompetitionStatus::SignupClosed])->save();

    $upload()->assertCreated()->assertJsonStructure(['proofMediaId']);

    expect(Storage::disk('media')->allFiles())->not->toBe([]);
});

/*
 * ---------------------------------------------------------------------------
 * Item 4, part 2, M4: interest only while a team can still be found
 * ---------------------------------------------------------------------------
 */

it('takes interest while signup is on or closed, refuses it once the competition runs or is over, and 404s a draft', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $eventId = competitionPublicEvent($brand->id);
    $competition = Competition::factory()->forEvent($eventId)->create();
    $player = User::factory()->create();
    competitionEligibleUser($eventId, $brand->id, (string) $player->getKey());

    $url = '/tournaments/'.$competition->id.'/interest';
    $refusal = 'Für dieses Turnier kannst du dich nicht mehr als interessiert eintragen: es hat schon begonnen oder ist vorbei.';

    foreach ([CompetitionStatus::Running, CompetitionStatus::Finished, CompetitionStatus::Cancelled] as $status) {
        $competition->forceFill(['status' => $status])->save();

        $this->actingAs($player)->post($url, ['note' => null])->assertSessionHasErrors(['note' => $refusal]);
    }

    $competition->forceFill(['status' => CompetitionStatus::Draft])->save();
    $this->actingAs($player)->post($url, ['note' => null])->assertNotFound();

    expect(CompetitionInterest::query()->where('competition_id', $competition->id)->exists())->toBeFalse();

    // The controls: `Published`, then `SignupClosed`, where §5.4 fills
    // incomplete rosters from this list. Six requests, inside the ten.
    foreach ([CompetitionStatus::Published, CompetitionStatus::SignupClosed] as $status) {
        $competition->forceFill(['status' => $status])->save();

        $this->actingAs($player)->post($url, ['note' => $status->value])->assertSessionHasNoErrors();
    }

    expect(CompetitionInterest::query()->where('competition_id', $competition->id)->sole()->note)->toBe('signup_closed');
});
