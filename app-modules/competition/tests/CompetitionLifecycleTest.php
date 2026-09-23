<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Konventix\Competition\Actions\CancelCompetition;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\FinishCompetition;
use Konventix\Competition\Actions\GenerateBracket;
use Konventix\Competition\Actions\PublishCompetition;
use Konventix\Competition\Actions\ReopenSignup;
use Konventix\Competition\Actions\StartCompetition;
use Konventix\Competition\Actions\UnpublishCompetition;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Events\CompetitionCancelled;
use Konventix\Competition\Events\CompetitionFinished;
use Konventix\Competition\Events\CompetitionPublished;
use Konventix\Competition\Events\CompetitionStarted;
use Konventix\Competition\Events\CompetitionUnpublished;
use Konventix\Competition\Events\SignupClosed;
use Konventix\Competition\Events\SignupOpened;
use Konventix\Competition\Exceptions\IllegalCompetitionStateTransitionException;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Entrant;
use Konventix\Competition\Models\Stage;

/**
 * The competition lifecycle (D-CMP-15, design §4): the transition table on
 * `CompetitionStatus`, the Actions that consult it, the two preconditions on
 * `start`, and the contract events each move emits.
 *
 * Also the consequence of design §7.1 — `competitions.format` is gone and
 * `GenerateBracket` reads the stage, which it always did.
 *
 * Helper names are process-global across every module (.ai/rules/tests.md);
 * the ones only this file uses are prefixed `lifecycleTest`.
 */
function lifecycleTestGeneratedStage(Competition $competition): Stage
{
    return Stage::factory()->create([
        'competition_id' => $competition->getKey(),
        'generated_at' => now(),
    ]);
}

it('names exactly the transitions design §4 draws', function (): void {
    expect(CompetitionStatus::Draft->allowedTransitions())
        ->toBe([CompetitionStatus::Published, CompetitionStatus::Cancelled])
        ->and(CompetitionStatus::Published->allowedTransitions())
        ->toBe([CompetitionStatus::Draft, CompetitionStatus::SignupClosed, CompetitionStatus::Cancelled])
        ->and(CompetitionStatus::SignupClosed->allowedTransitions())
        ->toBe([CompetitionStatus::Published, CompetitionStatus::Running, CompetitionStatus::Cancelled])
        ->and(CompetitionStatus::Running->allowedTransitions())
        ->toBe([CompetitionStatus::Finished, CompetitionStatus::Cancelled])
        // Finished is the one state Cancelled is not reachable from: a
        // tournament played to its end is history.
        ->and(CompetitionStatus::Finished->allowedTransitions())->toBe([])
        ->and(CompetitionStatus::Cancelled->allowedTransitions())->toBe([]);
});

it('walks draft to finished and emits one contract event per move', function (): void {
    $this->withBrand();

    Event::fake([
        CompetitionPublished::class,
        SignupOpened::class,
        SignupClosed::class,
        CompetitionStarted::class,
        CompetitionFinished::class,
    ]);

    $competition = Competition::factory()->status(CompetitionStatus::Draft)->create();

    app(PublishCompetition::class)->handle($competition);
    expect($competition->refresh()->status)->toBe(CompetitionStatus::Published)
        // `published_at` is the timestamp of the transition, never the state.
        ->and($competition->published_at)->not->toBeNull();

    app(CloseSignup::class)->handle($competition);
    expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);

    lifecycleTestGeneratedStage($competition);

    app(StartCompetition::class)->handle($competition);
    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);

    app(FinishCompetition::class)->handle($competition);
    expect($competition->refresh()->status)->toBe(CompetitionStatus::Finished);

    Event::assertDispatched(CompetitionPublished::class, fn (CompetitionPublished $e): bool => $e->competitionId === $competition->id
        && $e->eventId === $competition->event_id
        && $e->disciplineId === $competition->discipline_id
        && $e->brandId === $competition->brand_id
        && $e->status === 'published');
    // The factory's window is open, so publishing opened signup with it.
    Event::assertDispatched(SignupOpened::class);
    Event::assertDispatched(SignupClosed::class);
    Event::assertDispatched(CompetitionStarted::class);
    Event::assertDispatched(CompetitionFinished::class, fn (CompetitionFinished $e): bool => $e->status === 'finished');
});

it('unpublishes back to draft and reopens signup', function (): void {
    $this->withBrand();

    Event::fake([SignupOpened::class]);

    $competition = Competition::factory()->status(CompetitionStatus::Published)->create();

    app(UnpublishCompetition::class)->handle($competition);
    expect($competition->refresh()->status)->toBe(CompetitionStatus::Draft)
        ->and($competition->published_at)->toBeNull();

    app(PublishCompetition::class)->handle($competition);
    app(CloseSignup::class)->handle($competition);
    app(ReopenSignup::class)->handle($competition);

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Published);

    // Twice: once on the publish into an open window, once on the reopen —
    // design §4 names both as causes.
    Event::assertDispatchedTimes(SignupOpened::class, 2);
});

it('announces an unpublish once, with the event and brand it left', function (): void {
    $this->withBrand();

    Event::fake([CompetitionPublished::class, CompetitionUnpublished::class, SignupOpened::class]);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:00:00Z'));

    $competition = Competition::factory()->status(CompetitionStatus::Draft)->create();

    app(PublishCompetition::class)->handle($competition);

    // Nothing was taken back yet: publishing is not an unpublish.
    Event::assertNotDispatched(CompetitionUnpublished::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T10:15:00Z'));

    app(UnpublishCompetition::class)->handle($competition);

    Event::assertDispatchedTimes(CompetitionUnpublished::class, 1);
    Event::assertDispatched(CompetitionUnpublished::class, fn (CompetitionUnpublished $e): bool => $e->competitionId === $competition->id
        && $e->eventId === $competition->event_id
        && $e->brandId === $competition->brand_id
        // The clock stood at 10:15:00 UTC when the unpublish ran; the app
        // timezone is UTC, so toIso8601String() writes the +00:00 offset.
        && $e->unpublishedAt === '2026-10-01T10:15:00+00:00');
});

it('announces nothing when an unpublish is refused', function (): void {
    $this->withBrand();

    Event::fake([CompetitionUnpublished::class]);

    $competition = Competition::factory()->status(CompetitionStatus::SignupClosed)->create();

    // SignupClosed → Draft is not an arrow of design §4: the way back from a
    // closed signup is reopen, to Published.
    expect(fn () => app(UnpublishCompetition::class)->handle($competition))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);
    Event::assertNotDispatched(CompetitionUnpublished::class);
});

it('carries the competition slug on CompetitionPublished', function (): void {
    $this->withBrand();

    Event::fake([CompetitionPublished::class, SignupOpened::class]);

    $competition = Competition::factory()->status(CompetitionStatus::Draft)->create([
        'name' => 'Main Cup',
        'slug' => 'main-cup-2026',
    ]);

    app(PublishCompetition::class)->handle($competition);

    Event::assertDispatched(CompetitionPublished::class, fn (CompetitionPublished $e): bool => $e->competitionId === $competition->id
        && $e->name === 'Main Cup'
        // The slug as stored, not one re-derived from the name ('main-cup').
        && $e->slug === 'main-cup-2026');
});

it('cancels from every state but finished', function (): void {
    $this->withBrand();

    Event::fake([CompetitionCancelled::class]);

    foreach ([
        CompetitionStatus::Draft,
        CompetitionStatus::Published,
        CompetitionStatus::SignupClosed,
        CompetitionStatus::Running,
    ] as $status) {
        $competition = Competition::factory()->status($status)->create();

        app(CancelCompetition::class)->handle($competition);

        expect($competition->refresh()->status)->toBe(CompetitionStatus::Cancelled);
    }

    Event::assertDispatchedTimes(CompetitionCancelled::class, 4);

    $finished = Competition::factory()->status(CompetitionStatus::Finished)->create();

    expect(fn () => app(CancelCompetition::class)->handle($finished))
        ->toThrow(IllegalCompetitionStateTransitionException::class);
});

it('throws on an illegal transition rather than writing the state', function (): void {
    $this->withBrand();

    $published = Competition::factory()->status(CompetitionStatus::Published)->create();

    // Published → Finished is not an arrow: a tournament has to be started
    // before it can be over.
    expect(fn () => app(FinishCompetition::class)->handle($published))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    expect($published->refresh()->status)->toBe(CompetitionStatus::Published);

    $finished = Competition::factory()->status(CompetitionStatus::Finished)->create();

    expect(fn () => app(PublishCompetition::class)->handle($finished))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    $draft = Competition::factory()->status(CompetitionStatus::Draft)->create();

    expect(fn () => app(StartCompetition::class)->handle($draft))
        ->toThrow(IllegalCompetitionStateTransitionException::class);
});

it('refuses to start before a stage has been generated', function (): void {
    $this->withBrand();

    Event::fake([CompetitionStarted::class]);

    $competition = Competition::factory()->status(CompetitionStatus::SignupClosed)->create();

    // No stage at all.
    expect(fn () => app(StartCompetition::class)->handle($competition))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    // A stage that exists but was never drawn is no better: the desk would
    // show an empty board.
    Stage::factory()->create(['competition_id' => $competition->getKey()]);

    expect(fn () => app(StartCompetition::class)->handle($competition))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);
    Event::assertNotDispatched(CompetitionStarted::class);
});

it('refuses to start while a roster is incomplete', function (): void {
    // Design §5.4: an incomplete entrant holds a slot until the organizer
    // fills it from the interested list or declines it. Starting over one
    // would seed a team that cannot field itself.
    $this->withBrand();

    $competition = Competition::factory()->status(CompetitionStatus::SignupClosed)->create();
    lifecycleTestGeneratedStage($competition);

    Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Incomplete,
    ]);

    expect(fn () => app(StartCompetition::class)->handle($competition))
        ->toThrow(IllegalCompetitionStateTransitionException::class);

    expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);
});

it('carries the size of the field on SignupClosed, incomplete rosters included', function (): void {
    $this->withBrand();

    Event::fake([SignupClosed::class]);

    $competition = Competition::factory()->status(CompetitionStatus::Published)->create();

    Entrant::factory()->count(2)->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Registered,
    ]);
    Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Incomplete,
    ]);
    // Withdrawn holds no slot and must not be counted.
    Entrant::factory()->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Withdrawn,
    ]);

    app(CloseSignup::class)->handle($competition);

    Event::assertDispatched(SignupClosed::class, fn (SignupClosed $e): bool => $e->competitionId === $competition->id
        && $e->eventId === $competition->event_id
        && $e->brandId === $competition->brand_id
        && $e->entrantCount === 3);
});

it('derives signup from the state, not from the window alone', function (): void {
    $this->withBrand();

    $competition = Competition::factory()->status(CompetitionStatus::SignupClosed)->create();

    // The window still says open; the state is what closed signup.
    expect($competition->signup_closes_at?->isFuture())->toBeTrue()
        ->and($competition->signupIsOpen())->toBeFalse();

    $draft = Competition::factory()->status(CompetitionStatus::Draft)->create();
    expect($draft->signupIsOpen())->toBeFalse();

    $published = Competition::factory()->status(CompetitionStatus::Published)->create();
    expect($published->signupIsOpen())->toBeTrue();
});

it('generates a bracket from the stage now that competitions.format is gone', function (): void {
    // Design §7.1: the format moved to the stage. `GenerateBracket` always
    // read it there, so dropping the column reaches no engine code.
    $this->withBrand();

    expect(Schema::hasColumn('competitions', 'format'))->toBeFalse()
        ->and(Schema::hasColumn('competitions', 'status'))->toBeTrue()
        ->and(Schema::hasColumn('competitions', 'starts_at'))->toBeTrue();

    $competition = Competition::factory()->status(CompetitionStatus::SignupClosed)->create();

    Entrant::factory()->count(4)->create([
        'competition_id' => $competition->getKey(),
        'state' => EntrantState::Registered,
    ]);

    $stage = Stage::factory()->format('single_elimination')->create([
        'competition_id' => $competition->getKey(),
    ]);

    $plan = app(GenerateBracket::class)->handle($stage);

    expect($plan->matches)->toHaveCount(3)
        ->and($stage->refresh()->isGenerated())->toBeTrue();

    // And the competition can now start, which is the whole point of the
    // precondition.
    app(StartCompetition::class)->handle($competition);

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Running);
});
