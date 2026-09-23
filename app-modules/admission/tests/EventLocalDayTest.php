<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Actions\RecordCheckIn;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Presence\PresenceWindow;
use Konventix\Admission\Support\LatestAcceptedCheckIn;

/**
 * The event day is the event's LOCAL date (#329 final review I1, ADR-0027):
 * `EntranceEventProfile::dayCovering()` converts the moment into the event's
 * zone before reading its date, so the core, the console and the attendee
 * list resolve the day the door device (`evaluate.ts`, the device clock) and
 * the hub (`PresenceWindow`) already resolve.
 *
 * Every event here is in Europe/Berlin (`ticketEventSummary()`'s default).
 * 16–17 October 2026 is summer time, CEST = UTC+2 (it ends on 25 October),
 * so every local instant below is its UTC instant plus two hours:
 *
 * | UTC               | Berlin (CEST)     | local date |
 * |-------------------|-------------------|------------|
 * | 2026-10-16 18:00  | 2026-10-16 20:00  | 16th       |
 * | 2026-10-16 21:59  | 2026-10-16 23:59  | 16th       |
 * | 2026-10-16 22:00  | 2026-10-17 00:00  | 17th       |
 * | 2026-10-16 22:30  | 2026-10-17 00:30  | 17th       |
 * | 2026-10-16 23:45  | 2026-10-17 01:45  | 17th       |
 * | 2026-10-17 00:30  | 2026-10-17 02:30  | 17th       |
 * | 2026-10-17 22:00  | 2026-10-18 00:00  | 18th       |
 *
 * The old core read the UTC date, so every row from 22:00 UTC to midnight
 * UTC sat on the previous day.
 *
 * Helper names prefixed `eventLocalDay*` — Pest helpers are process-global
 * (.ai/rules/tests.md), and none of these is called from another file.
 *
 * @param  list<array{day_id: string, date: string, sequence: int, label: string}>  $days
 */
function eventLocalDayProfile(string $eventId, array $days, ReentryPolicy $policy = ReentryPolicy::OncePerDay): EntranceEventProfile
{
    return EntranceEventProfile::factory()
        ->policy($policy)
        ->withDays($days)
        ->create(['event_id' => $eventId]);
}

/**
 * @return array{eventId: string, friday: string, saturday: string, profile: EntranceEventProfile}
 */
function eventLocalDayWeekend(ReentryPolicy $policy = ReentryPolicy::OncePerDay): array
{
    $eventId = (string) Str::ulid();
    $friday = (string) Str::ulid();
    $saturday = (string) Str::ulid();

    $profile = eventLocalDayProfile($eventId, [
        ['day_id' => $friday, 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag'],
        ['day_id' => $saturday, 'date' => '2026-10-17', 'sequence' => 2, 'label' => 'Samstag'],
    ], $policy);

    return ['eventId' => $eventId, 'friday' => $friday, 'saturday' => $saturday, 'profile' => $profile];
}

function eventLocalDayUtc(string $utc): CarbonImmutable
{
    return CarbonImmutable::parse($utc, 'UTC');
}

it('resolves the day on the event\'s local date around local midnight', function (): void {
    $brand = $this->withBrand();
    $event = eventLocalDayWeekend();
    ticketEventSummary($brand, $event['eventId']);
    $profile = $event['profile'];

    // 21:59 UTC = 23:59 CEST on the 16th → Friday; 22:00 UTC = 00:00 CEST on
    // the 17th → Saturday, although the UTC date is still the 16th.
    expect($profile->dayCovering(eventLocalDayUtc('2026-10-16 21:59:59'))['day_id'])->toBe($event['friday'])
        ->and($profile->dayCovering(eventLocalDayUtc('2026-10-16 22:00:00'))['day_id'])->toBe($event['saturday'])
        // 22:30 UTC on the 16th = 00:30 CEST on the 17th → Saturday.
        ->and($profile->dayCovering(eventLocalDayUtc('2026-10-16 22:30:00'))['day_id'])->toBe($event['saturday'])
        // The same instant written with its local offset reads the same day.
        ->and($profile->dayCovering(CarbonImmutable::parse('2026-10-17T00:30:00+02:00'))['day_id'])->toBe($event['saturday'])
        // 22:00 UTC on the 17th = 00:00 CEST on the 18th, which has no day.
        ->and($profile->dayCovering(eventLocalDayUtc('2026-10-17 22:00:00')))->toBeNull()
        ->and($profile->eventTimezone())->toBe('Europe/Berlin');
});

it('falls back to the UTC date while the event has no summary', function (): void {
    $this->withBrand();
    $event = eventLocalDayWeekend();

    // No `admission_event_summaries` row, so no zone: UTC, as PresenceWindow
    // falls back too. 22:30 UTC on the 16th is then the 16th → Friday.
    expect($event['profile']->eventTimezone())->toBe('UTC')
        ->and($event['profile']->dayCovering(eventLocalDayUtc('2026-10-16 22:30:00'))['day_id'])->toBe($event['friday']);
});

it('admits at 00:30 local on a day whose UTC date is still the evening before', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();
    $saturday = (string) Str::ulid();
    ticketEventSummary($brand, $eventId);
    // A calendar with Saturday only, so the UTC reading had no day at all.
    eventLocalDayProfile($eventId, [
        ['day_id' => $saturday, 'date' => '2026-10-17', 'sequence' => 1, 'label' => 'Samstag'],
    ]);
    $admission = Admission::factory()->create(['event_id' => $eventId]);

    // Derivation: 22:30 UTC on the 16th is 00:30 CEST on the 17th, Saturday.
    // The old core read 2026-10-16, found no day and wrote
    // `outside_event_day`; the local date finds Saturday, the factory
    // admission is issued with an open window, and it has no prior entry, so
    // `once_per_day` admits → Accepted on Saturday.
    $checkIn = app(RecordCheckIn::class)->handle($admission, $eventId, 'local-midnight', eventLocalDayUtc('2026-10-16 22:30:00'));

    expect($checkIn->result)->toBe(CheckInResult::Accepted)
        ->and($checkIn->reason_code)->toBeNull()
        ->and($checkIn->event_day_id)->toBe($saturday);
});

it('refuses a once_per_day re-entry between 00:00 and 02:00 local and after it, on the new local day', function (): void {
    $brand = $this->withBrand();
    $event = eventLocalDayWeekend(ReentryPolicy::OncePerDay);
    ticketEventSummary($brand, $event['eventId']);
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $core = app(RecordCheckIn::class);

    // 18:00 UTC on the 16th = 20:00 CEST Friday: first entry → Friday.
    $friday = $core->handle($admission, $event['eventId'], 'fri-20-00', eventLocalDayUtc('2026-10-16 18:00:00'));
    // 22:30 UTC on the 16th = 00:30 CEST Saturday: a new local day with no
    // entry yet → admitted on Saturday. (The UTC date read Friday and
    // refused this as a re-entry.)
    $saturday = $core->handle($admission, $event['eventId'], 'sat-00-30', eventLocalDayUtc('2026-10-16 22:30:00'));
    // 23:45 UTC on the 16th = 01:45 CEST Saturday: Saturday already has an
    // entry → refused.
    $early = $core->handle($admission, $event['eventId'], 'sat-01-45', eventLocalDayUtc('2026-10-16 23:45:00'));
    // 00:30 UTC on the 17th = 02:30 CEST Saturday: still Saturday → refused.
    // (The UTC date read Saturday for the first time here and admitted it:
    // the second entry of one local day.)
    $late = $core->handle($admission, $event['eventId'], 'sat-02-30', eventLocalDayUtc('2026-10-17 00:30:00'));

    expect($friday->result)->toBe(CheckInResult::Accepted)
        ->and($friday->event_day_id)->toBe($event['friday'])
        ->and($saturday->result)->toBe(CheckInResult::Accepted)
        ->and($saturday->event_day_id)->toBe($event['saturday'])
        ->and($early->result)->toBe(CheckInResult::Rejected)
        ->and($early->reason_code)->toBe(CheckInRejectionReason::ReentryNotPermitted->value)
        ->and($early->event_day_id)->toBe($event['saturday'])
        ->and($late->result)->toBe(CheckInResult::Rejected)
        ->and($late->reason_code)->toBe(CheckInRejectionReason::ReentryNotPermitted->value)
        ->and($late->event_day_id)->toBe($event['saturday']);

    // Derivation: four rows, the Friday and Saturday entries accepted.
    expect(CheckIn::query()->where('admission_id', $admission->id)->where('result', CheckInResult::Accepted)->count())->toBe(2)
        ->and(CheckIn::query()->where('admission_id', $admission->id)->count())->toBe(4);
});

it('gives the console, the list and the hub the same local day as the core', function (): void {
    $brand = $this->withBrand();
    $event = eventLocalDayWeekend(ReentryPolicy::OncePerDay);
    ticketEventSummary($brand, $event['eventId']);
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $at = eventLocalDayUtc('2026-10-16 22:30:00');

    // Derivation: 22:30 UTC on the 16th = 00:30 CEST on the 17th → Saturday,
    // for the list's "in today" (`LatestAcceptedCheckIn::day()`), for the
    // hub's window (`PresenceWindow`, which converts into the zone itself)
    // and for a console check-in at that moment. The guest has no row, so
    // the console does not refuse them as "already in", and the core admits
    // (first Saturday entry under `once_per_day`).
    $day = LatestAcceptedCheckIn::day($brand->id, $event['eventId'], $at);
    $window = app(PresenceWindow::class)->for($event['profile'], 'Europe/Berlin', $at);
    $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in', at: $at);

    expect($day->dayId)->toBe($event['saturday'])
        ->and($day->outsideEveryDay)->toBeFalse()
        ->and($window['day_label'])->toBe('Samstag')
        ->and($checkIn->result)->toBe(CheckInResult::Accepted)
        ->and($checkIn->event_day_id)->toBe($event['saturday'])
        ->and(LatestAcceptedCheckIn::direction($brand->id, $admission->id, $day->dayId))->toBe('in');
});
