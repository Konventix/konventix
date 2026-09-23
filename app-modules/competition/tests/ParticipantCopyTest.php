<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\TeamRole;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Exceptions\ResultSubmissionException;
use Konventix\Competition\Http\Controllers\Concerns\AnswersTheParticipant;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeamMember;
use Konventix\Identity\Models\User;

/**
 * Participant copy is German, console copy English (ADR-0001, design §14
 * "The participant's refusals are German").
 *
 * Each refusal keeps its English message for the desk, the log and the
 * tests, and a cause a participant can reach carries a German sentence
 * beside it. What this file pins is the split itself, not the wording of
 * every sentence: that each reachable cause has a German sentence of its
 * own, that none of them leaks a ULID, a design section or a code name, that
 * a console-only cause falls back to a generic German sentence, and that the
 * join form's answer stays one sentence whatever the cause.
 *
 * Helper names prefixed: Pest helpers are process-global
 * (`.ai/rules/tests.md`).
 */
function participantCopyGeneric(): string
{
    return 'Das hat nicht geklappt. Lade die Seite neu und versuch es noch einmal.';
}

/**
 * Every cause a participant can reach, by the route that reaches it.
 *
 * @return array<string, RuntimeException>
 */
function participantCopyReachable(): array
{
    $id = '01K5ZZZZZZZZZZZZZZZZZZZZZZ';

    return [
        // Enter (`POST /tournaments/{competition}/entries`).
        'signupClosed' => CompetitionEntryException::signupClosed($id),
        'alreadyEntered' => CompetitionEntryException::alreadyEntered($id, $id),
        'competitionFull' => CompetitionEntryException::competitionFull($id, 16),
        'wrongEvent' => CompetitionEntryException::wrongEvent($id, $id),
        'emptyRoster' => CompetitionEntryException::emptyRoster($id),
        'rosterFull' => CompetitionEntryException::rosterFull($id, $id, 2, 3),
        // Enter, create a team, register interest.
        'noConfirmedRegistration' => CompetitionEntryException::noConfirmedRegistration($id, $id),
        'alreadyOnTeam' => CompetitionEntryException::alreadyOnTeam($id, $id),
        // Withdraw and self-service check-in on `/my/tournaments`.
        'competitionOver' => CompetitionEntryException::competitionOver($id),
        'entrantAlreadyLeft' => CompetitionEntryException::entrantAlreadyLeft($id),
        'entrantNotCheckable' => CompetitionEntryException::entrantNotCheckable($id, 'incomplete'),
        'selfServiceCheckInClosed' => CompetitionEntryException::selfServiceCheckInClosed($id),
        'notTheCaptain' => CompetitionEntryException::notTheCaptain($id, $id),
        'rosterNotAtTheDoor' => CompetitionEntryException::rosterNotAtTheDoor($id, ['Mia', 'Tom']),
        // Interest once the competition runs or is over (fix wave B, M4).
        'interestClosed' => CompetitionEntryException::interestClosed($id, 'running'),
        // Join by code.
        'unknownInviteCode' => CompetitionEntryException::unknownInviteCode('ZZZZZZZZ'),
        // Report, confirm, dispute, a score entry, a proof upload.
        'deskOnly' => ResultSubmissionException::deskOnly($id),
        'takesNoProofs' => ResultSubmissionException::takesNoProofs($id),
        'takesNoMoreProofs' => ResultSubmissionException::takesNoMoreProofs($id, 'finished'),
        'notASide' => ResultSubmissionException::notASide($id, $id),
        'notOnRoster' => ResultSubmissionException::notOnRoster($id, $id),
        'alreadyRecorded' => ResultSubmissionException::alreadyRecorded($id),
        'matchNotPlayable' => ResultSubmissionException::matchNotPlayable($id, 'cancelled'),
        'incompleteScoreLine' => ResultSubmissionException::incompleteScoreLine($id),
        'nothingToConfirm' => ResultSubmissionException::nothingToConfirm($id),
        'cannotConfirmOwnReport' => ResultSubmissionException::cannotConfirmOwnReport($id, $id),
        'sideStillToCome' => ResultSubmissionException::sideStillToCome($id),
        'drawNotPossible' => ResultSubmissionException::drawNotPossible($id, 'single_elimination'),
        'notALeaderboard' => ResultSubmissionException::notALeaderboard($id, 'swiss'),
        'notInTheField' => ResultSubmissionException::notInTheField($id, $id),
        'noLongerScores' => ResultSubmissionException::noLongerScores($id, 'withdrawn'),
        // Dispute only: a report or a confirm on a competition that is over
        // is refused by the lifecycle guard first, whose German sentence
        // `AnswersTheParticipant` supplies (final review, part 4, I2), and a
        // re-report during a dispute no longer disputes again (part 1, I5).
        'alreadyDisputed' => ResultSubmissionException::alreadyDisputed($id),
        'resultsOver' => ResultSubmissionException::competitionOver($id, 'finished'),
    ];
}

it('gives every cause a participant can reach a German sentence of its own, free of internals', function (): void {
    foreach (participantCopyReachable() as $cause => $exception) {
        /** @var CompetitionEntryException|ResultSubmissionException $exception */
        $german = $exception->participantMessage();

        expect($german)->not->toBe($exception->getMessage(), $cause.' shows the console message')
            ->and($german)->not->toBe(participantCopyGeneric(), $cause.' falls back to the generic sentence')
            // No ULID, no bracketed id, no design section, no code value.
            ->and($german)->not->toContain('01K5ZZZZ')
            ->and($german)->not->toContain('[')
            ->and($german)->not->toContain('§')
            ->and($german)->not->toContain('`');
    }
});

it('keeps the English message for the console and the log', function (): void {
    expect(ResultSubmissionException::deskOnly('01K5ZZZZZZZZZZZZZZZZZZZZZZ')->getMessage())
        ->toBe('Competition [01K5ZZZZZZZZZZZZZZZZZZZZZZ] is set to `desk_only`: the organizers record results, '
            .'participants do not submit them (design §8.1).');
});

it('answers a console-only cause with the generic German sentence', function (): void {
    $id = '01K5ZZZZZZZZZZZZZZZZZZZZZZ';

    foreach ([
        CompetitionEntryException::teamsAtDifferentEvents($id, $id),
        CompetitionEntryException::notOnTeam($id, $id),
        CompetitionEntryException::entrantNotIncomplete($id),
        CompetitionEntryException::entrantNotCheckedIn($id),
        ResultSubmissionException::notDisputed($id),
        ResultSubmissionException::resolveInstead($id),
    ] as $exception) {
        expect($exception->participantMessage())->toBe(participantCopyGeneric());
    }
});

it('fills the numbers and names in', function (): void {
    expect(CompetitionEntryException::competitionFull('x', 16)->participantMessage())
        ->toBe('Dieses Turnier ist voll: alle 16 Plätze sind vergeben.')
        ->and(CompetitionEntryException::rosterFull('t', 'c', 2, 3)->participantMessage())
        ->toBe('Das Team wäre mit 3 Leuten zu groß: in einem seiner Turniere spielen höchstens 2 pro Team.')
        ->and(CompetitionEntryException::rosterNotAtTheDoor('e', ['Mia', 'Tom'])->participantMessage())
        ->toBe('Einchecken geht erst, wenn alle im Team am Einlass waren. Noch nicht eingelassen: Mia, Tom.');
});

it('answers an unknown invite code with the join form\'s one sentence, and names no cause', function (): void {
    expect(CompetitionEntryException::unknownInviteCode('ZZZZZZZZ')->participantMessage())
        ->toBe(CompetitionEntryException::joinRefusal())
        ->and(CompetitionEntryException::joinRefusal())
        ->toBe('Dieser Code passt nicht — er ist unbekannt, das Team ist voll, du bist schon dabei, '
            .'oder du bist für diese Veranstaltung nicht angemeldet.');
});

it('refuses interest from somebody without a registration in German', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    $competition = Competition::factory()->forEvent(competitionPublicEvent($brand->id))->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post('/tournaments/'.$competition->getKey().'/interest', ['note' => null])
        ->assertSessionHasErrors([
            'note' => 'Dafür braucht es eine bestätigte Anmeldung zur Veranstaltung oder ein Ticket auf den eigenen Namen – für dich und für alle im Team.',
        ]);
});

it('turns a proof away in German where the competition takes none', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('competition', $brand);

    Storage::fake('media');

    [$competition, $field] = competitionFieldWithRosters(1, [
        'proof_policy' => ProofPolicy::None,
        'event_id' => competitionPublicEvent($brand->id),
    ]);

    // A person who can sign in, on the side's roster, so the refusal is the
    // policy's and not the membership check's.
    $player = User::factory()->create();
    $member = new CompetitionTeamMember([
        'competition_team_id' => $field[0]->competition_team_id,
        'user_id' => (string) $player->getKey(),
        'role' => TeamRole::Member,
    ]);
    $member->forceFill(['brand_id' => $field[0]->brand_id])->save();

    $this->actingAs($player)
        ->post('/tournaments/'.$competition->getKey().'/proofs', [
            'entrant_id' => (string) $field[0]->getKey(),
            'file' => UploadedFile::fake()->image('scoreboard.png'),
        ])
        ->assertSessionHasErrors(['file' => 'Dieses Turnier nimmt keine Beweisbilder an.']);

    expect(Storage::disk('media')->allFiles())->toBe([]);
});

it('answers a match that moved on under the participant with one German sentence on the field', function (): void {
    // Final review, part 4, I2: nothing in the suite asserted this mapping.
    // Since the fix wave re-reads the match under the lock before every
    // participant check (part 1, I3), a settled match is refused with its own
    // German cause, and no participant route reaches the state machine's
    // refusal any more — so the mapping is pinned here, on the concern every
    // participant controller answers through, rather than through a race a
    // test cannot stage.
    $controller = new class
    {
        use AnswersTheParticipant;

        public function answer(callable $work, string $field): mixed
        {
            return $this->attempt($work, $field);
        }
    };

    try {
        $controller->answer(static function (): never {
            throw new IllegalMatchStateTransitionException('Match [01J0000000000000000000000] cannot transition from [finished] to [finished].');
        }, 'result');

        $this->fail('The refusal was not answered as a form error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['result' => ['Dieses Spiel hat sich inzwischen geändert. Lade die Seite neu.']]);
    }
});
