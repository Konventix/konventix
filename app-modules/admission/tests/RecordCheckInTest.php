<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\RecordCheckIn;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceDeltaEntry;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Tokens\AdmissionTokenPayload;

/**
 * The shared check-in core (attendee-list design §3.1, D-ATT-08): an
 * admission handed straight to `RecordCheckIn`, with no token, runs the same
 * admission-level checks in the same order as a door scan and writes the
 * same row. The door's own behaviour is pinned, unchanged, by the existing
 * door-scan tests (§7); this file covers what the core adds — the actor and
 * the answered conditions on the row — and the checks it now owns.
 *
 * Helper names prefixed `recordCheckIn*` — Pest helpers are process-global
 * (.ai/rules/tests.md), and none of these is called from another file.
 *
 * @return array{eventId: string, days: list<array{day_id: string, date: string, sequence: int, label: string}>}
 */
function recordCheckInCalendar(int $dayCount = 1, ReentryPolicy $policy = ReentryPolicy::Unlimited): array
{
    $eventId = (string) Str::ulid();
    $days = [];

    for ($i = 0; $i < $dayCount; $i++) {
        $days[] = [
            'day_id' => (string) Str::ulid(),
            'date' => now()->addDays($i)->format('Y-m-d'),
            'sequence' => $i + 1,
            'label' => 'Day '.($i + 1),
        ];
    }

    EntranceEventProfile::factory()
        ->policy($policy)
        ->withDays($days)
        ->create(['event_id' => $eventId]);

    return ['eventId' => $eventId, 'days' => $days];
}

/**
 * The upload the door sends when staff answered a document check, in the
 * shape `EntryConditionsAtTheDoorTest` posts: one scan carrying
 * `conditions_checked`, here with the student check refused.
 *
 * @param  list<array{key: string, outcome: string}>  $conditionsChecked
 * @return array{scans: list<array{token: string, client_scan_id: string, conditions_checked?: list<array{key: string, outcome: string}>}>}
 */
function recordCheckInDoorUpload(string $token, string $clientScanId, array $conditionsChecked = [['key' => 'student', 'outcome' => 'refused']]): array
{
    $scan = ['token' => $token, 'client_scan_id' => $clientScanId];

    if ($conditionsChecked !== []) {
        $scan['conditions_checked'] = $conditionsChecked;
    }

    return ['scans' => [$scan]];
}

it('accepts an admission handed to the core without a token, and stamps the actor and the answered conditions', function (): void {
    $this->withBrand();
    Event::fake([AdmissionCheckedIn::class]);

    $calendar = recordCheckInCalendar();
    $admission = Admission::factory()->create(['event_id' => $calendar['eventId']]);
    $actorUserId = (string) Str::ulid();
    $answered = [['key' => 'student', 'outcome' => 'confirmed']];

    // Derivation: the calendar's single day is dated today and the instant is
    // now(), so `dayCovering()` returns day one; no day scope is passed, so
    // every day is admitted; the factory admission is `issued` with an open
    // validity window, so it is valid; the policy is unlimited, so re-entry
    // admits. Every check passes → Accepted, stamped with day one.
    $checkIn = app(RecordCheckIn::class)->handle(
        admission: $admission,
        eventId: $calendar['eventId'],
        clientScanId: 'console-1',
        at: now(),
        conditionsChecked: $answered,
        actorUserId: $actorUserId,
    );

    $row = $checkIn->fresh();

    // `conditions_checked` is jsonb, which stores object keys shortest-first:
    // `key` (3) before `outcome` (7) — the order written, so it reads back
    // identically.
    expect($row->result)->toBe(CheckInResult::Accepted)
        ->and($row->reason_code)->toBeNull()
        ->and($row->event_day_id)->toBe($calendar['days'][0]['day_id'])
        ->and($row->registration_id)->toBe($admission->registration_id)
        ->and($row->actor_user_id)->toBe($actorUserId)
        ->and($row->conditions_checked)->toBe($answered)
        ->and($row->checkpoint_id)->toBeNull()
        ->and($row->device_id)->toBeNull();

    // Direction `in` feeds the delta exactly once, as a door accept does.
    expect(EntranceDeltaEntry::query()
        ->where('admission_id', $admission->id)
        ->where('kind', EntranceDeltaEntry::KIND_CHECKED_IN)
        ->count())->toBe(1);

    Event::assertDispatched(AdmissionCheckedIn::class, fn (AdmissionCheckedIn $event): bool => $event->checkInId === $row->id
        && $event->admissionId === $admission->id
        && $event->registrationId === $admission->registration_id
        && $event->eventId === $calendar['eventId']
        && $event->eventDayId === $calendar['days'][0]['day_id']
        && $event->direction === 'in'
        && $event->conditionsChecked === $answered);
});

it('returns the first row for a replayed client scan id and emits nothing twice', function (): void {
    $this->withBrand();
    Event::fake([AdmissionCheckedIn::class, CheckInRejected::class]);

    $calendar = recordCheckInCalendar();
    $admission = Admission::factory()->create(['event_id' => $calendar['eventId']]);

    $first = app(RecordCheckIn::class)->handle($admission, $calendar['eventId'], 'replayed', now());
    $second = app(RecordCheckIn::class)->handle($admission, $calendar['eventId'], 'replayed', now());

    // Derivation: the second call finds the brand's row for `replayed` before
    // any check runs and returns it — one row, one delta entry, one event,
    // and no rejection (unlimited re-entry would have admitted again anyway,
    // so a second row would have been an accept, not a reject).
    expect($second->id)->toBe($first->id)
        ->and(CheckIn::query()->where('admission_id', $admission->id)->count())->toBe(1)
        ->and(EntranceDeltaEntry::query()->where('admission_id', $admission->id)->count())->toBe(1);

    Event::assertDispatchedTimes(AdmissionCheckedIn::class, 1);
    Event::assertNotDispatched(CheckInRejected::class);
});

it('reads a day scope the way AdmissionTokenPayload::admitsOnDay() does', function (): void {
    $this->withBrand();
    Event::fake([AdmissionCheckedIn::class, CheckInRejected::class]);

    $calendar = recordCheckInCalendar(dayCount: 2);
    [$d1, $d2] = [$calendar['days'][0]['day_id'], $calendar['days'][1]['day_id']];
    $onDayTwo = now()->addDay();

    $scoped = Admission::factory()->create(['event_id' => $calendar['eventId']]);
    $unscoped = Admission::factory()->create(['event_id' => $calendar['eventId']]);

    // Derivation: `$onDayTwo` is dated tomorrow, which is day two's date, so
    // the instant falls on d2. Scope `[d1]` does not contain d2 → refused
    // `not_valid_on_this_day`, stamped with d2 (the day was found, it is just
    // not admitted). Scope `[]` is every day → accepted on d2.
    $refused = app(RecordCheckIn::class)->handle($scoped, $calendar['eventId'], 'scope-d1', $onDayTwo, admittedDayIds: [$d1]);
    $accepted = app(RecordCheckIn::class)->handle($unscoped, $calendar['eventId'], 'scope-all', $onDayTwo, admittedDayIds: []);

    expect($refused->result)->toBe(CheckInResult::Rejected)
        ->and($refused->reason_code)->toBe(CheckInRejectionReason::NotValidOnThisDay->value)
        ->and($refused->event_day_id)->toBe($d2)
        ->and($accepted->result)->toBe(CheckInResult::Accepted)
        ->and($accepted->event_day_id)->toBe($d2);

    // Parity with the token's own predicate, for the same two scopes on d2.
    $payload = fn (array $dayIds): AdmissionTokenPayload => new AdmissionTokenPayload(
        admissionId: $scoped->id,
        eventId: $calendar['eventId'],
        nonce: 'n',
        issuedAt: 0,
        expiresAt: 0,
        dayIds: $dayIds,
    );

    expect($payload([$d1])->admitsOnDay($d2))->toBeFalse()
        ->and($payload([])->admitsOnDay($d2))->toBeTrue();
});

it('refuses outside every event day before it asks whether the admission is valid', function (): void {
    $this->withBrand();
    Event::fake([AdmissionCheckedIn::class, CheckInRejected::class]);

    $calendar = recordCheckInCalendar();
    $revoked = Admission::factory()->create([
        'event_id' => $calendar['eventId'],
        'state' => AdmissionState::Revoked,
    ]);

    // Derivation: the calendar has one day, dated today; seven days later has
    // no day, so the day check fails first. The admission is revoked as well,
    // which would read `admission_not_valid` had validity been asked first —
    // `outside_event_day` proves the order. No day was found, so no day id.
    $checkIn = app(RecordCheckIn::class)->handle($revoked, $calendar['eventId'], 'a-week-late', now()->addDays(7));

    expect($checkIn->result)->toBe(CheckInResult::Rejected)
        ->and($checkIn->reason_code)->toBe(CheckInRejectionReason::OutsideEventDay->value)
        ->and($checkIn->event_day_id)->toBeNull();

    Event::assertDispatched(CheckInRejected::class, fn (CheckInRejected $event): bool => $event->reasonCode === 'outside_event_day');
    Event::assertNotDispatched(AdmissionCheckedIn::class);
});

it('writes a rejected row through reject() and emits CheckInRejected with the reason', function (): void {
    $brand = $this->withBrand();
    Event::fake([CheckInRejected::class]);

    $eventId = (string) Str::ulid();
    $at = now();

    $checkIn = app(RecordCheckIn::class)->reject(
        $brand->id, $eventId, null, null, 'bad-signature', $at, null, null, 'in', CheckInRejectionReason::InvalidSignature,
    );

    $row = $checkIn->fresh();

    // Derivation: a rejected row never carries a registration (it is null
    // exactly when the check-in was refused), never carries condition
    // outcomes, and no actor was passed. The reason's value is the enum's
    // backing string, `invalid_signature`.
    expect($row->result)->toBe(CheckInResult::Rejected)
        ->and($row->reason_code)->toBe('invalid_signature')
        ->and($row->registration_id)->toBeNull()
        ->and($row->admission_id)->toBeNull()
        ->and($row->actor_user_id)->toBeNull()
        ->and($row->conditions_checked)->toBeNull()
        ->and($row->brand_id)->toBe($brand->id);

    Event::assertDispatched(CheckInRejected::class, fn (CheckInRejected $event): bool => $event->checkInId === $row->id
        && $event->admissionId === null
        && $event->eventId === $eventId
        && $event->brandId === $brand->id
        && $event->reasonCode === CheckInRejectionReason::InvalidSignature->value
        && $event->scannedAt === $at->toIso8601String());
});

it('stamps no actor on a door scan, and the uploaded answer as conditions_checked', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    Event::fake([AdmissionCheckedIn::class]);

    $lane = entranceTestLane();
    $answered = Admission::factory()->create(['event_id' => $lane['eventId']]);
    $unasked = Admission::factory()->create(['event_id' => $lane['eventId']]);

    $this->postJson('/api/entrance/scans', recordCheckInDoorUpload(
        app(IssueAdmissionToken::class)->current($answered),
        'door-answered',
    ), entranceTestHeaders($lane['token']))->assertOk()->assertJsonPath('accepted', 1);

    $this->postJson('/api/entrance/scans', recordCheckInDoorUpload(
        app(IssueAdmissionToken::class)->current($unasked),
        'door-unasked',
        [],
    ), entranceTestHeaders($lane['token']))->assertOk()->assertJsonPath('accepted', 1);

    // Derivation: the event has no projected profile, so it is not
    // day-scoped and re-entry is unlimited; both admissions are issued; both
    // scans are accepted. A door has no acting user → actor null. The first
    // upload answered `[{key: student, outcome: refused}]`, which lands on
    // the row as written (jsonb keeps `key` before `outcome`); the second
    // answered nothing, and an empty list is stored as null.
    $answeredRow = CheckIn::query()->where('client_scan_id', 'door-answered')->sole();
    $unaskedRow = CheckIn::query()->where('client_scan_id', 'door-unasked')->sole();

    expect($answeredRow->result)->toBe(CheckInResult::Accepted)
        ->and($answeredRow->actor_user_id)->toBeNull()
        ->and($answeredRow->conditions_checked)->toBe([['key' => 'student', 'outcome' => 'refused']])
        ->and($answeredRow->checkpoint_id)->toBe($lane['checkpoint']->id)
        ->and($answeredRow->device_id)->toBe($lane['device']->id)
        ->and($unaskedRow->actor_user_id)->toBeNull()
        ->and($unaskedRow->conditions_checked)->toBeNull();
});
