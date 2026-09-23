<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Konventix\Competition\Actions\CloseSignup;
use Konventix\Competition\Actions\PublishCompetition;
use Konventix\Competition\Actions\ReopenSignup;
use Konventix\Competition\Actions\UnpublishCompetition;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Events\SignupClosed;
use Konventix\Competition\Events\SignupOpened;
use Konventix\Competition\Models\Competition;
use Konventix\Platform\Modules\ModuleGate;
use Laravel\Pennant\Feature;

/**
 * The signup clock of design §4 (`competition:signup-tick`, final review fix
 * wave B, item 1): `SignupClosed` when `signup_closes_at` passes, and
 * `SignupOpened` once when a `Published` competition's window opens.
 *
 * Every boundary is pinned to the second on both sides. The edges are
 * `Competition::signupIsOpen()`'s: open while `signup_opens_at <= now <
 * signup_closes_at`. So at `signup_opens_at` itself the window is open (the
 * tick announces it) and at `signup_closes_at` itself it is closed (the tick
 * closes it); one second earlier, neither.
 *
 * Helper names prefixed: Pest helpers are process-global across every
 * module's test files (`.ai/rules/tests.md`).
 */
function signupTickTestAt(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

beforeEach(function (): void {
    $this->activateModule('competition');
});

it('closes signup at signup_closes_at and not one second before', function (): void {
    Event::fake([SignupClosed::class, SignupOpened::class]);

    // Window 1 Oct 10:00 → 8 Oct 18:00 UTC, announced at its opening, so the
    // announce pass has nothing to say and only the close is under test.
    $competition = Competition::factory()->create([
        'signup_opens_at' => signupTickTestAt('2026-10-01 10:00:00'),
        'signup_closes_at' => signupTickTestAt('2026-10-08 18:00:00'),
        'signup_opened_at' => signupTickTestAt('2026-10-01 10:00:00'),
    ]);

    // 17:59:59 < 18:00:00: signup is still open, the state stays Published.
    $this->travelTo(signupTickTestAt('2026-10-08 17:59:59'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    expect($competition->refresh()->status)->toBe(CompetitionStatus::Published);
    Event::assertNotDispatched(SignupClosed::class);

    // 18:00:00 = signup_closes_at: `now < signup_closes_at` is false, closed.
    $this->travelTo(signupTickTestAt('2026-10-08 18:00:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    expect($competition->refresh()->status)->toBe(CompetitionStatus::SignupClosed);
    Event::assertDispatchedTimes(SignupClosed::class, 1);
    Event::assertDispatched(SignupClosed::class, fn (SignupClosed $event): bool => $event->competitionId === $competition->id
        && $event->entrantCount === 0);

    // A minute later the competition is no longer Published: nothing twice.
    $this->travelTo(signupTickTestAt('2026-10-08 18:01:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    Event::assertDispatchedTimes(SignupClosed::class, 1);
    Event::assertNotDispatched(SignupOpened::class);
});

it('announces SignupOpened at signup_opens_at, once, and not one second before', function (): void {
    Event::fake([SignupClosed::class, SignupOpened::class]);

    $competition = Competition::factory()->create([
        'signup_opens_at' => signupTickTestAt('2026-10-01 10:00:00'),
        'signup_closes_at' => signupTickTestAt('2026-10-08 18:00:00'),
    ]);

    // 09:59:59 < 10:00:00: `now < signup_opens_at`, so signup is not open yet.
    $this->travelTo(signupTickTestAt('2026-10-01 09:59:59'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    Event::assertNotDispatched(SignupOpened::class);
    expect($competition->refresh()->signup_opened_at)->toBeNull();

    // 10:00:00 = signup_opens_at: open, and never announced → announced now.
    $this->travelTo(signupTickTestAt('2026-10-01 10:00:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    Event::assertDispatchedTimes(SignupOpened::class, 1);
    Event::assertDispatched(SignupOpened::class, fn (SignupOpened $event): bool => $event->competitionId === $competition->id
        && $event->at === '2026-10-01T10:00:00+00:00');
    expect($competition->refresh()->signup_opened_at?->toIso8601String())->toBe('2026-10-01T10:00:00+00:00')
        ->and($competition->status)->toBe(CompetitionStatus::Published);

    // 10:01: announced at 10:00, which is not before the 10:00 opening.
    $this->travelTo(signupTickTestAt('2026-10-01 10:01:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    Event::assertDispatchedTimes(SignupOpened::class, 1);
    Event::assertNotDispatched(SignupClosed::class);
});

it('announces a window the organizer moved past the last announcement, when it opens', function (): void {
    Event::fake([SignupOpened::class]);

    // Announced on 1 Oct at 10:00; the organizer then pushed the opening to
    // 3 Oct 12:00. 12:00 on the 3rd is after 10:00 on the 1st, so the window
    // open from then was never announced.
    $competition = Competition::factory()->create([
        'signup_opens_at' => signupTickTestAt('2026-10-03 12:00:00'),
        'signup_closes_at' => signupTickTestAt('2026-10-08 18:00:00'),
        'signup_opened_at' => signupTickTestAt('2026-10-01 10:00:00'),
    ]);

    $this->travelTo(signupTickTestAt('2026-10-03 11:59:59'));
    $this->artisan('competition:signup-tick')->assertSuccessful();
    Event::assertNotDispatched(SignupOpened::class);

    $this->travelTo(signupTickTestAt('2026-10-03 12:00:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    Event::assertDispatchedTimes(SignupOpened::class, 1);
    expect($competition->refresh()->signup_opened_at?->toIso8601String())->toBe('2026-10-03T12:00:00+00:00');
});

it('does not repeat what a reopen or a publish announced, and an unpublish clears the stamp', function (): void {
    Event::fake([SignupClosed::class, SignupOpened::class]);

    $this->travelTo(signupTickTestAt('2026-10-02 09:00:00'));

    $competition = Competition::factory()->create([
        'signup_opens_at' => signupTickTestAt('2026-10-01 10:00:00'),
        'signup_closes_at' => signupTickTestAt('2026-10-08 18:00:00'),
        'signup_opened_at' => signupTickTestAt('2026-10-01 10:00:00'),
    ]);

    app(CloseSignup::class)->handle($competition);
    app(ReopenSignup::class)->handle($competition);

    // ReopenSignup emitted SignupOpened itself (09:00 on the 2nd, after the
    // 10:00-on-the-1st opening) and stamped it: the tick stays quiet.
    Event::assertDispatchedTimes(SignupOpened::class, 1);

    $this->travelTo(signupTickTestAt('2026-10-02 09:01:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();
    Event::assertDispatchedTimes(SignupOpened::class, 1);

    app(UnpublishCompetition::class)->handle($competition->refresh());
    expect($competition->refresh()->signup_opened_at)->toBeNull();

    // Published again at 09:01, inside the open window: PublishCompetition
    // announces it (the second SignupOpened) and stamps it, so the tick at
    // 09:02 has nothing left to say.
    app(PublishCompetition::class)->handle($competition);

    Event::assertDispatchedTimes(SignupOpened::class, 2);
    expect($competition->refresh()->signup_opened_at?->toIso8601String())->toBe('2026-10-02T09:01:00+00:00');

    $this->travelTo(signupTickTestAt('2026-10-02 09:02:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    Event::assertDispatchedTimes(SignupOpened::class, 2);
});

it('leaves a brand with competition switched off alone', function (): void {
    Event::fake([SignupClosed::class, SignupOpened::class]);

    $closing = Competition::factory()->create([
        'signup_opens_at' => signupTickTestAt('2026-10-01 10:00:00'),
        'signup_closes_at' => signupTickTestAt('2026-10-08 18:00:00'),
        'signup_opened_at' => signupTickTestAt('2026-10-01 10:00:00'),
    ]);
    $opening = Competition::factory()->create([
        'signup_opens_at' => signupTickTestAt('2026-10-08 12:00:00'),
        'signup_closes_at' => signupTickTestAt('2026-10-15 18:00:00'),
    ]);

    Feature::for($this->withBrand())->deactivate(app(ModuleGate::class)->featureName('competition'));

    // 18:00 on the 8th is both the first one's close and after the second
    // one's opening: with the module on, the tick would act on each.
    $this->travelTo(signupTickTestAt('2026-10-08 18:00:00'));
    $this->artisan('competition:signup-tick')->assertSuccessful();

    expect($closing->refresh()->status)->toBe(CompetitionStatus::Published)
        ->and($opening->refresh()->signup_opened_at)->toBeNull();
    Event::assertNotDispatched(SignupClosed::class);
    Event::assertNotDispatched(SignupOpened::class);

    // Switched back on, the same minute acts on both — so the quiet run above
    // was the gate, not a broken query.
    $this->activateModule('competition');
    $this->artisan('competition:signup-tick')->assertSuccessful();

    expect($closing->refresh()->status)->toBe(CompetitionStatus::SignupClosed);
    Event::assertDispatchedTimes(SignupClosed::class, 1);
    Event::assertDispatchedTimes(SignupOpened::class, 1);
});

it('is scheduled every minute without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'competition:signup-tick'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
