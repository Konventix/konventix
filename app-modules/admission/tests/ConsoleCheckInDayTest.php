<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\CheckInFromConsole;
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
