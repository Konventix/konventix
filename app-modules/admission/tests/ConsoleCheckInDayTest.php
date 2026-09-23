<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Exceptions\ConsoleCheckInRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Support\LatestAcceptedCheckIn;

/**
 * "Already in" is read on the current event day, as the door reads it (#329,
 * Task 3 review I1): a guest scanned in on day 1 and never scanned out, whose
 * phone is dead on day 2, is admitted by the door on day 2 — and so by the
 * console. `Support\LatestAcceptedCheckIn` is the one place that reading
 * lives.
 *
 * The event has two calendar days, 2026-10-16 (day 1) and 2026-10-17
 * (day 2), under `unlimited` re-entry. Time is frozen at 18:00 UTC on the day
 * each step happens on; `dayCovering()` compares the moment's own Y-m-d, so
 * 2026-10-16 18:00 UTC is day 1 and 2026-10-17 18:00 UTC is day 2.
 */
function consoleCheckInDayEvent(): array
{
    $eventId = (string) Str::ulid();
    $day1 = (string) Str::ulid();
    $day2 = (string) Str::ulid();

    EntranceEventProfile::factory()
        ->policy(ReentryPolicy::Unlimited)
        ->withDays([
            ['day_id' => $day1, 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag'],
            ['day_id' => $day2, 'date' => '2026-10-17', 'sequence' => 2, 'label' => 'Samstag'],
        ])
        ->create(['event_id' => $eventId]);

    return ['eventId' => $eventId, 'day1' => $day1, 'day2' => $day2];
}

it('checks a guest in from the console on day 2 who went in on day 1 and never out', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInDayEvent();
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();

    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
    app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    // Still day 1: the same guest is in, so a second `in` is refused.
    $sameDay = null;

    try {
        app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');
    } catch (ConsoleCheckInRefusedException $refusal) {
        $sameDay = $refusal;
    }

    expect($sameDay?->reason)->toBe(ConsoleCheckInRefusedException::ALREADY_IN);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 18:00:00', 'UTC'));

    // Day 2: no accepted row carries day 2's id, so the guest reads "not in"
    // and the check-in goes through the core, which admits (unlimited).
    expect(LatestAcceptedCheckIn::direction($brand->id, $admission->id, $event['day2']))->toBeNull()
        ->and(LatestAcceptedCheckIn::direction($brand->id, $admission->id, $event['day1']))->toBe('in');

    $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    expect($checkIn->result)->toBe(CheckInResult::Accepted)
        ->and($checkIn->event_day_id)->toBe($event['day2'])
        // Derivation: day 1's accepted `in`, day 2's accepted `in` — the
        // refused second `in` on day 1 wrote nothing.
        ->and(CheckIn::query()->where('admission_id', $admission->id)->count())->toBe(2);
});

it('reads every accepted row when the event has no calendar', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $admission = Admission::factory()->create(['event_id' => $eventId]);

    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
    CheckIn::factory()->create(['admission_id' => $admission->id, 'direction' => 'in', 'scanned_at' => now()]);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 18:00:00', 'UTC'));

    // No profile, so no day: the one accepted `in` from the day before counts.
    expect(LatestAcceptedCheckIn::dayFor($brand->id, $eventId, now()))->toBeNull()
        ->and(LatestAcceptedCheckIn::direction($brand->id, $admission->id, null))->toBe('in');
});

it('falls back to every accepted row when the calendar has no day for the moment, and says so', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInDayEvent();
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);

    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
    app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in');

    // 2026-10-19: the calendar has 2026-10-16 and 2026-10-17 only.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 18:00:00', 'UTC'));
    $day = LatestAcceptedCheckIn::day($brand->id, $event['eventId'], now());

    // Derivation: no covering day, so no day id and "outside every day";
    // with no day id every accepted row counts, and the latest is day 1's `in`.
    expect($day->dayId)->toBeNull()
        ->and($day->outsideEveryDay)->toBeTrue()
        ->and(LatestAcceptedCheckIn::direction($brand->id, $admission->id, $day->dayId))->toBe('in');

    // An event with no calendar is not "outside every day".
    expect(LatestAcceptedCheckIn::day($brand->id, (string) Str::ulid(), now())->outsideEveryDay)->toBeFalse();
});

it('lets the core refuse as outside_event_day when no event day covers the moment, never "already in" or "not in"', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInDayEvent();
    $wasIn = Admission::factory()->create(['event_id' => $event['eventId']]);
    $neverIn = Admission::factory()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();

    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
    app(CheckInFromConsole::class)->handle($wasIn, $event['eventId'], $actorId, 'in');

    // 2026-10-19 lies outside both calendar days.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 18:00:00', 'UTC'));

    // Derivation: read across every day, $wasIn is `in` (would have thrown
    // ALREADY_IN on `in`) and $neverIn is not (would have thrown NOT_IN on
    // `out`). Outside every day those refusals are skipped, and the core
    // rejects all three attempts with outside_event_day, writing a row each.
    $again = app(CheckInFromConsole::class)->handle($wasIn, $event['eventId'], $actorId, 'in');
    $out = app(CheckInFromConsole::class)->handle($wasIn, $event['eventId'], $actorId, 'out');
    $notIn = app(CheckInFromConsole::class)->handle($neverIn, $event['eventId'], $actorId, 'out');

    foreach ([$again, $out, $notIn] as $checkIn) {
        expect($checkIn->result)->toBe(CheckInResult::Rejected)
            ->and($checkIn->reason_code)->toBe(CheckInRejectionReason::OutsideEventDay->value);
    }

    // Derivation: day 1's accepted `in` plus the three rejected rows.
    expect(CheckIn::query()->count())->toBe(4);
});
