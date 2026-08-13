<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Competition\Actions\CorrectMatchResult;
use Konventix\Competition\Actions\EnterCompetition;
use Konventix\Competition\Actions\RecordMatchResult;
use Konventix\Competition\Actions\ScheduleMatch;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Events\EntrantRegistered;
use Konventix\Competition\Events\MatchFinished;
use Konventix\Competition\Events\MatchResultCorrected;
use Konventix\Competition\Events\MatchScheduled;
use Konventix\Competition\Exceptions\CompetitionEntryException;
use Konventix\Competition\Exceptions\IllegalMatchStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Discipline;
use Konventix\Competition\Models\EligibleRegistration;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\MatchSlot;
use Konventix\Registration\Events\RegistrationConfirmed;
use Konventix\Registration\Events\RegistrationSubmitted;

/**
 * Entry gating (D-CMP-05), per-event teams (D-CMP-13/14) and the result state
 * machine (D-CMP-11/12).
 *
 * Helper names prefixed — Pest helpers are process-global (.ai/rules/tests.md).
 */
function compTestEligible(string $eventId, string $brandId, ?string $userId = null): string
{
    $userId ??= (string) Str::ulid();
    $registrationId = (string) Str::ulid();

    // Arrives the only sanctioned way: through Registration's contract events.
    event(new RegistrationSubmitted(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'on_submit',
        submittedAt: now()->toIso8601String(),
    ));

    event(new RegistrationConfirmed(
        registrationId: $registrationId,
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brandId,
        confirmedAt: now()->toIso8601String(),
        grants: [],
    ));

    return $userId;
}

function compTestCompetition(?Discipline $discipline = null): Competition
{
    return Competition::factory()
        ->when($discipline !== null, fn ($f) => $f->state(['discipline_id' => $discipline->id]))
        ->create();
}

function compTestMatchWithSlots(Competition $competition, int $sides = 2): CompetitionMatch
{
    $match = CompetitionMatch::factory()->create(['competition_id' => $competition->id]);

    for ($i = 1; $i <= $sides; $i++) {
        $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();
        $entrant = Entrant::factory()->create([
            'competition_id' => $competition->id,
            'competition_team_id' => $team->id,
        ]);

        $slot = new MatchSlot([
            'competition_match_id' => $match->id,
            'entrant_id' => $entrant->id,
            'position' => $i,
        ]);
        $slot->forceFill(['brand_id' => $match->brand_id])->save();
    }

    return $match->refresh();
}

it('projects entry eligibility from Registration contract events', function (): void {
    // D-CMP-05 answered without ever reading Registration's tables.
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    $userId = compTestEligible($eventId, $brand->id);

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $userId))->toBeTrue();
});

it('needs both Registration events before anyone may enter', function (): void {
    // Submission knows who; confirmation knows they are entitled. Neither
    // alone answers "may this person enter".
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $userId = (string) Str::ulid();

    event(new RegistrationSubmitted(
        registrationId: (string) Str::ulid(),
        registrationTypeId: (string) Str::ulid(),
        eventId: $eventId,
        brandId: $brand->id,
        registrantUserId: $userId,
        settlement: 'free',
        confirmationGate: 'on_approval',
        submittedAt: now()->toIso8601String(),
    ));

    expect(EligibleRegistration::userMayEnter($brand->id, $eventId, $userId))->toBeFalse();
});

it('enters a team whose members all hold confirmed registrations', function (): void {
    $brand = $this->withBrand();

    $competition = compTestCompetition();
    $userId = compTestEligible($competition->event_id, $brand->id);
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

    Event::fake([EntrantRegistered::class]);

    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$userId]);

    expect($entrant->state)->toBe(EntrantState::Registered);
    Event::assertDispatched(EntrantRegistered::class);
});

it('refuses entry without a confirmed registration', function (): void {
    // D-CMP-05: a confirmed Registration is what entitles someone to enter.
    $brand = $this->withBrand();

    $competition = compTestCompetition();
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team, [(string) Str::ulid()]))
        ->toThrow(CompetitionEntryException::class);
});

it('does not ask for an admission at entry time', function (): void {
    // D-CMP-05 splits the gates: admission possession is verified at check-in
    // or match start, not here. The Action must not reach for one.
    $source = file_get_contents(base_path('app-modules/competition/src/Actions/EnterCompetition.php'));

    expect($source)->not->toContain('Admission');
});

it('refuses a team belonging to a different event', function (): void {
    // Teams are per event (D-CMP-14).
    $brand = $this->withBrand();

    $competition = compTestCompetition();
    $userId = compTestEligible($competition->event_id, $brand->id);
    $foreignTeam = CompetitionTeam::factory()->create();

    expect(fn () => app(EnterCompetition::class)->handle($competition, $foreignTeam, [$userId]))
        ->toThrow(CompetitionEntryException::class);
});

it('refuses entry when signup is closed', function (): void {
    $brand = $this->withBrand();

    $competition = Competition::factory()->signupClosed()->create();
    $userId = compTestEligible($competition->event_id, $brand->id);
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team, [$userId]))
        ->toThrow(CompetitionEntryException::class);
});

it('refuses entry to an unpublished competition', function (): void {
    $brand = $this->withBrand();

    $competition = Competition::factory()->unpublished()->create();
    $userId = compTestEligible($competition->event_id, $brand->id);
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team, [$userId]))
        ->toThrow(CompetitionEntryException::class);
});

it('stops a team entering twice at the database level', function (): void {
    // A bracket containing the same team twice is a tournament nobody can run.
    $brand = $this->withBrand();

    $competition = compTestCompetition();
    $userId = compTestEligible($competition->event_id, $brand->id);
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

    app(EnterCompetition::class)->handle($competition, $team, [$userId]);

    expect(fn () => app(EnterCompetition::class)->handle($competition, $team, [$userId]))
        ->toThrow(CompetitionEntryException::class);

    expect(Entrant::query()->where('competition_id', $competition->id)->count())->toBe(1);
});

it('treats a solo player as a team of one', function (): void {
    // D-CMP-13: one shape, so the bracket engine never branches on whether a
    // side is a person or a squad.
    $brand = $this->withBrand();

    $discipline = Discipline::factory()->teamSize(1)->create();
    $competition = compTestCompetition($discipline);
    $userId = compTestEligible($competition->event_id, $brand->id);
    $team = CompetitionTeam::factory()->forEvent($competition->event_id)->create();

    $entrant = app(EnterCompetition::class)->handle($competition, $team, [$userId]);

    expect($discipline->isSolo())->toBeTrue()
        ->and($entrant->competition_team_id)->toBe($team->id);
});

it('emits MatchScheduled with the game reference for a video-game discipline', function (): void {
    $this->withBrand();

    $gameId = (string) Str::ulid();
    $competition = compTestCompetition(Discipline::factory()->videoGame($gameId)->create());
    $match = compTestMatchWithSlots($competition);

    Event::fake([MatchScheduled::class]);

    app(ScheduleMatch::class)->handle($match, now()->addHour(), gameMode: 'competitive');

    Event::assertDispatched(
        MatchScheduled::class,
        fn (MatchScheduled $e): bool => $e->gameId === $gameId
            && $e->gameMode === 'competitive'
            && count($e->entrantIds) === 2,
    );
});

it('emits MatchScheduled with no game for a discipline that is not a video game', function (): void {
    // ADR-0024: GameOps handles only the disciplines that are video games, so
    // a scheduled quiz gives it nothing to provision.
    $this->withBrand();

    $competition = compTestCompetition();
    $match = compTestMatchWithSlots($competition);

    Event::fake([MatchScheduled::class]);

    app(ScheduleMatch::class)->handle($match, now()->addHour(), gameMode: 'ignored');

    Event::assertDispatched(
        MatchScheduled::class,
        fn (MatchScheduled $e): bool => $e->gameId === null && $e->gameMode === null,
    );
});

it('records a result directly, without a desk gate', function (): void {
    // D-CMP-11: handler results record directly and advance the bracket.
    $this->withBrand();

    $competition = compTestCompetition();
    $match = compTestMatchWithSlots($competition);
    $entrantIds = $match->slots->pluck('entrant_id')->all();

    Event::fake([MatchFinished::class]);

    $finished = app(RecordMatchResult::class)->handle(
        $match,
        [$entrantIds[0] => 2, $entrantIds[1] => 1],
        $entrantIds[0],
    );

    expect($finished->state)->toBe(MatchState::Finished)
        ->and($finished->winner_entrant_id)->toBe($entrantIds[0])
        ->and(MatchSlot::query()->where('entrant_id', $entrantIds[0])->first()->score)->toBe(2);

    Event::assertDispatched(MatchFinished::class);
});

it('emits MatchFinished exactly once even when the result is corrected', function (): void {
    // D-CMP-12, and the reason GameOps never tears the same deployment down
    // twice.
    $this->withBrand();

    $competition = compTestCompetition();
    $match = compTestMatchWithSlots($competition);
    $entrantIds = $match->slots->pluck('entrant_id')->all();

    Event::fake([MatchFinished::class, MatchResultCorrected::class]);

    app(RecordMatchResult::class)->handle($match, [$entrantIds[0] => 2, $entrantIds[1] => 1], $entrantIds[0]);
    app(CorrectMatchResult::class)->handle(
        $match->fresh(),
        [$entrantIds[0] => 1, $entrantIds[1] => 2],
        $entrantIds[1],
        correctedByUserId: (string) Str::ulid(),
    );

    Event::assertDispatchedTimes(MatchFinished::class, 1);
    Event::assertDispatched(
        MatchResultCorrected::class,
        fn (MatchResultCorrected $e): bool => $e->previousWinnerEntrantId === $entrantIds[0]
            && $e->winnerEntrantId === $entrantIds[1],
    );
});

it('rewrites the score and winner on correction', function (): void {
    $this->withBrand();

    $competition = compTestCompetition();
    $match = compTestMatchWithSlots($competition);
    $entrantIds = $match->slots->pluck('entrant_id')->all();

    app(RecordMatchResult::class)->handle($match, [$entrantIds[0] => 2, $entrantIds[1] => 1], $entrantIds[0]);
    $corrected = app(CorrectMatchResult::class)->handle(
        $match->fresh(),
        [$entrantIds[0] => 0, $entrantIds[1] => 2],
        $entrantIds[1],
    );

    expect($corrected->winner_entrant_id)->toBe($entrantIds[1])
        ->and(MatchSlot::query()->where('entrant_id', $entrantIds[1])->first()->score)->toBe(2)
        // Still finished, and still recorded as having emitted once.
        ->and($corrected->state)->toBe(MatchState::Finished)
        ->and($corrected->finished_event_emitted_at)->not->toBeNull();
});

it('refuses to correct a match that has not finished', function (): void {
    // Correcting a match still in play is not a correction; it is recording.
    $this->withBrand();

    $match = compTestMatchWithSlots(compTestCompetition());

    expect(fn () => app(CorrectMatchResult::class)->handle($match, [], null))
        ->toThrow(IllegalMatchStateTransitionException::class);
});

it('refuses to record a result twice', function (): void {
    $this->withBrand();

    $competition = compTestCompetition();
    $match = compTestMatchWithSlots($competition);
    $entrantIds = $match->slots->pluck('entrant_id')->all();

    app(RecordMatchResult::class)->handle($match, [$entrantIds[0] => 2], $entrantIds[0]);

    expect(fn () => app(RecordMatchResult::class)->handle($match->fresh(), [$entrantIds[1] => 2], $entrantIds[1]))
        ->toThrow(IllegalMatchStateTransitionException::class);
});
