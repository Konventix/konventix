<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\CheckInFromConsole;
use Konventix\Admission\Actions\IssueAdmissionToken;
use Konventix\Admission\Actions\IssueCrewAdmission;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\CheckInRejectionReason;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Admission\Enums\CheckpointKind;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\AdmissionCheckedIn;
use Konventix\Admission\Events\CheckInRejected;
use Konventix\Admission\Events\ConsoleCheckInRecorded;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Exceptions\ConsoleCheckInRefusedException;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\Checkpoint;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Admission\Support\OwedDoorChecks;
use Konventix\Compliance\Models\AuditLogEntry;
use Konventix\Platform\Brands\BrandSlugs;
use Konventix\Platform\Models\Brand;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * The console check-in (#329, attendee-list design §3.1, §4.3, D-ATT-04,
 * D-ATT-08): a ticket checked in or out by an operator goes through the same
 * core as a door scan, writes the same row with the admin on it, and has the
 * same effects — the assertions here are the ones the door-scan and presence
 * tests make (`EventPresenceProjectionTest`, `EntryConditionsAtTheDoorTest`,
 * `EntranceDayAndReentryTest`). What the console adds is pinned too: the
 * refusals that write nothing, the event scoping, the audit and the metric.
 *
 * Every test runs at 2026-10-16 18:00 UTC (20:00 in Berlin), and the event's
 * one calendar day is 2026-10-16, so `dayCovering(now())` is that day.
 *
 * Helper names prefixed `consoleCheckIn*` — Pest helpers are process-global
 * (.ai/rules/tests.md), and none of these is called from another file.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
});

/**
 * @return array{eventId: string, dayId: string}
 */
function consoleCheckInEvent(Brand $brand, ReentryPolicy $policy = ReentryPolicy::Unlimited): array
{
    $eventId = (string) Str::ulid();
    $dayId = (string) Str::ulid();

    ticketEventSummary($brand, $eventId);
    EntranceEventProfile::factory()
        ->policy($policy)
        ->withDays([['day_id' => $dayId, 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag', 'day_ends_at' => '03:00']])
        ->create(['event_id' => $eventId]);

    return ['eventId' => $eventId, 'dayId' => $dayId];
}

/**
 * A student ticket that owes one document check at the door, and a press
 * condition an operator ticks off in the console — which the door, and so
 * the check-in dialog, never asks about.
 *
 * @return list<array{key: string, label: string, verification: string, document_hint: string|null}>
 */
function consoleCheckInConditions(): array
{
    return [
        ['key' => 'student', 'label' => 'Student ticket', 'verification' => 'document_at_door', 'document_hint' => 'Bring a valid student ID'],
        ['key' => 'press', 'label' => 'Press pass', 'verification' => 'operator_checked', 'document_hint' => null],
    ];
}

/**
 * The refusal an attempt threw, or null when it did not throw one.
 */
function consoleCheckInRefusal(callable $attempt): ?ConsoleCheckInRefusedException
{
    try {
        $attempt();
    } catch (ConsoleCheckInRefusedException $refusal) {
        return $refusal;
    }

    return null;
}

function consoleCheckInMetrics(): string
{
    return (new RenderTextFormat)->render(app(CollectorRegistry::class)->getMetricFamilySamples());
}

it('checks a holder in through the shared core, with the admin, the console checkpoint and no device', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $holder = ticketPerson();
    $admission = Admission::factory()->assigned($holder->id)->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();

    Event::fake([ParticipantAdmitted::class, ParticipantPresenceChanged::class]);
    $seen = [];
    Event::listen(AdmissionCheckedIn::class, function (AdmissionCheckedIn $checkedIn) use (&$seen): void {
        $seen[] = $checkedIn;
    });

    $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    $row = $checkIn->fresh();
    $console = Checkpoint::query()->where('kind', CheckpointKind::Console->value)->sole();

    // Derivation: the one calendar day is today, the admission is assigned
    // with an open window, the policy is unlimited and nothing came before —
    // every check passes, so the row is accepted on that day. The console
    // stamps the admin, its own checkpoint and no device; the scan id is a
    // fresh `console-` one.
    expect($row->result)->toBe(CheckInResult::Accepted)
        ->and($row->direction)->toBe('in')
        ->and($row->actor_user_id)->toBe($actorId)
        ->and($row->checkpoint_id)->toBe($console->id)
        ->and($row->device_id)->toBeNull()
        ->and($row->event_day_id)->toBe($event['dayId'])
        ->and($row->registration_id)->toBe($admission->registration_id)
        ->and($row->client_scan_id)->toStartWith('console-')
        ->and($console->name)->toBe('Console')
        ->and($console->event_id)->toBe($event['eventId'])
        ->and($console->brand_id)->toBe($brand->id);

    // Exactly what a scan emits, with the console checkpoint's id on it.
    expect($seen)->toHaveCount(1)
        ->and($seen[0]->checkInId)->toBe($row->id)
        ->and($seen[0]->checkpointId)->toBe($console->id)
        ->and($seen[0]->registrationId)->toBe($admission->registration_id)
        ->and($seen[0]->eventDayId)->toBe($event['dayId'])
        ->and($seen[0]->direction)->toBe('in')
        ->and($seen[0]->conditionsChecked)->toBe([]);

    // The holder's hub opens, as for a scan (`EventPresenceProjectionTest`).
    $presence = EventPresence::query()->where('user_id', $holder->id)->sole();
    expect($presence->hub_access)->toBeTrue()
        ->and($presence->last_direction)->toBe('in')
        ->and($presence->admission_id)->toBe($admission->id);

    Event::assertDispatchedTimes(ParticipantAdmitted::class, 1);
    Event::assertDispatched(ParticipantAdmitted::class, fn (ParticipantAdmitted $admitted): bool => $admitted->userId === $holder->id);
});

it('checks the holder out again, and the badge flips to out', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $holder = ticketPerson();
    $admission = Admission::factory()->assigned($holder->id)->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();

    app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    Event::fake([ParticipantPresenceChanged::class]);
    $this->travel(1)->hours();

    $out = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'out');

    // Derivation: the last accepted row is the `in` an hour ago, so `out` is
    // allowed; a check-out runs no re-entry check and the ticket is valid,
    // so it is accepted. The presence row keeps its access and flips its
    // direction, as `EventPresenceProjectionTest` shows for a scan.
    expect($out->result)->toBe(CheckInResult::Accepted)
        ->and($out->direction)->toBe('out')
        ->and($out->actor_user_id)->toBe($actorId);

    $presence = EventPresence::query()->where('user_id', $holder->id)->sole();
    expect($presence->last_direction)->toBe('out')
        ->and($presence->hub_access)->toBeTrue();

    Event::assertDispatched(ParticipantPresenceChanged::class, fn (ParticipantPresenceChanged $changed): bool => $changed->direction === 'out'
        && $changed->userId === $holder->id);
});

it('records a confirmed door check on the row and the event, and owes nothing afterwards that day', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleCheckInConditions(),
    ]);
    $actorId = (string) Str::ulid();

    // Derivation: of the two conditions only `student` is `document_at_door`,
    // and nothing has answered it yet — one owed check, with its hint.
    expect(OwedDoorChecks::for($admission, $event['dayId']))->toBe([
        ['key' => 'student', 'label' => 'Student ticket', 'documentHint' => 'Bring a valid student ID'],
    ]);

    $seen = [];
    Event::listen(AdmissionCheckedIn::class, function (AdmissionCheckedIn $checkedIn) use (&$seen): void {
        $seen[] = $checkedIn;
    });

    $answered = [['key' => 'student', 'outcome' => 'confirmed']];
    $first = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in', $answered);

    // jsonb keeps `key` (3) before `outcome` (7), the order written.
    expect($first->fresh()->result)->toBe(CheckInResult::Accepted)
        ->and($first->fresh()->conditions_checked)->toBe($answered)
        ->and($seen[0]->conditionsChecked)->toBe($answered);

    // Answered on this day by an accepted check-in → nothing owed.
    expect(OwedDoorChecks::for($admission, $event['dayId']))->toBe([]);

    app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'out');
    $again = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    // Derivation: unlimited re-entry after the check-out, and no answers are
    // owed, so a check-in with none is accepted and stores none.
    expect($again->result)->toBe(CheckInResult::Accepted)
        ->and($again->fresh()->conditions_checked)->toBeNull();
});

it('checks the holder in on a refused door check, and the ticket stays issued', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleCheckInConditions(),
    ]);

    $refused = [['key' => 'student', 'outcome' => 'refused']];
    $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in', $refused);

    // Derivation: a refusal is an answer, so nothing is owed; the door's
    // rule (ADR-0066 §5, `EntryConditionsAtTheDoorTest`'s "accepted: 1")
    // is that it records the fact and still lets the holder in, and it
    // revokes nothing (T-8): the factory's `issued` state stands.
    expect($checkIn->result)->toBe(CheckInResult::Accepted)
        ->and($checkIn->fresh()->conditions_checked)->toBe($refused)
        ->and($admission->fresh()->state)->toBe(AdmissionState::Issued)
        ->and(OwedDoorChecks::for($admission, $event['dayId']))->toBe([]);
});

it('refuses unanswered or unknown door checks before anything is written', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleCheckInConditions(),
    ]);
    Event::fake([ConsoleCheckInRecorded::class, AdmissionCheckedIn::class, CheckInRejected::class]);

    $attempt = fn (array $answers): ?ConsoleCheckInRefusedException => consoleCheckInRefusal(
        fn () => app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in', $answers),
    );

    $unanswered = $attempt([]);
    // `press` is operator-checked: the ticket does not owe it at the door.
    $unknownKey = $attempt([['key' => 'student', 'outcome' => 'confirmed'], ['key' => 'press', 'outcome' => 'confirmed']]);
    $unknownOutcome = $attempt([['key' => 'student', 'outcome' => 'waived']]);
    $twice = $attempt([['key' => 'student', 'outcome' => 'confirmed'], ['key' => 'student', 'outcome' => 'refused']]);

    expect($unanswered?->reason)->toBe(ConsoleCheckInRefusedException::CHECKS_UNANSWERED)
        ->and($unanswered?->getMessage())->toContain('Student ticket')
        ->and($unknownKey?->reason)->toBe(ConsoleCheckInRefusedException::CHECKS_INVALID)
        ->and($unknownOutcome?->reason)->toBe(ConsoleCheckInRefusedException::CHECKS_INVALID)
        ->and($twice?->reason)->toBe(ConsoleCheckInRefusedException::CHECKS_INVALID);

    // Derivation: four attempts, each refused before the core ran — no
    // check-in, no console checkpoint, no event of any kind.
    expect(CheckIn::query()->count())->toBe(0)
        ->and(Checkpoint::query()->count())->toBe(0);

    Event::assertNotDispatched(ConsoleCheckInRecorded::class);
    Event::assertNotDispatched(AdmissionCheckedIn::class);
    Event::assertNotDispatched(CheckInRejected::class);
});

it('asks no door checks of a ticket that fails validity, and writes the door\'s admission_not_valid instead', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $revoked = Admission::factory()->revoked()->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleCheckInConditions(),
    ]);
    // The window closed the day before the clock (2026-10-16 18:00 UTC).
    $expired = Admission::factory()
        ->validBetween(CarbonImmutable::parse('2026-10-14 00:00:00', 'UTC'), CarbonImmutable::parse('2026-10-15 23:59:59', 'UTC'))
        ->create([
            'event_id' => $event['eventId'],
            'entry_conditions' => consoleCheckInConditions(),
        ]);
    $actorId = (string) Str::ulid();

    $revokedRow = app(CheckInFromConsole::class)->handle($revoked, $event['eventId'], $actorId, 'in');
    // Answers sent anyway (the dialog showed the check) are dropped, not
    // refused: the core rejects, and a rejected row carries none.
    $expiredRow = app(CheckInFromConsole::class)->handle($expired, $event['eventId'], $actorId, 'in', [['key' => 'student', 'outcome' => 'confirmed']]);

    // Derivation: both tickets owe the `student` check on a valid ticket, but
    // one is revoked and the other's valid_until (10-15 23:59:59) is before
    // now (10-16 18:00), so validity fails for both. They owe nothing, no
    // ConsoleCheckInRefusedException is thrown, and the core writes
    // `admission_not_valid` for each — not `checks_unanswered`.
    expect($revokedRow->result)->toBe(CheckInResult::Rejected)
        ->and($revokedRow->reason_code)->toBe(CheckInRejectionReason::AdmissionNotValid->value)
        ->and($expiredRow->result)->toBe(CheckInResult::Rejected)
        ->and($expiredRow->reason_code)->toBe(CheckInRejectionReason::AdmissionNotValid->value)
        ->and($expiredRow->fresh()->conditions_checked)->toBeNull()
        ->and(CheckIn::query()->count())->toBe(2);
});

it('asks no door checks outside every event day, and writes the door\'s outside_event_day instead', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create([
        'event_id' => $event['eventId'],
        'entry_conditions' => consoleCheckInConditions(),
    ]);

    // The calendar's only day is 2026-10-16; four days later no day covers.
    $this->travelTo(CarbonImmutable::parse('2026-10-20 18:00:00', 'UTC'));

    $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], (string) Str::ulid(), 'in');

    // Derivation: a calendar exists and `dayCovering(2026-10-20)` is null, so
    // the ticket owes nothing and the core's first check refuses:
    // `outside_event_day`, with no day id — not `checks_unanswered`.
    expect($checkIn->result)->toBe(CheckInResult::Rejected)
        ->and($checkIn->reason_code)->toBe(CheckInRejectionReason::OutsideEventDay->value)
        ->and($checkIn->event_day_id)->toBeNull();
});

it('writes a rejected row for a revoked ticket, exactly as the door does, with a sentence for the reason', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->revoked()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();
    Event::fake([AdmissionCheckedIn::class, CheckInRejected::class]);

    $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    // Derivation: the day check passes (today is the calendar day), the
    // ticket carries no day scope, and validity fails on `revoked` — the
    // core records `admission_not_valid`, with the admin on the row.
    expect($checkIn->result)->toBe(CheckInResult::Rejected)
        ->and($checkIn->reason_code)->toBe(CheckInRejectionReason::AdmissionNotValid->value)
        ->and($checkIn->actor_user_id)->toBe($actorId)
        ->and($checkIn->device_id)->toBeNull();

    Event::assertDispatched(CheckInRejected::class, fn (CheckInRejected $rejected): bool => $rejected->checkInId === $checkIn->id
        && $rejected->admissionId === $admission->id
        && $rejected->reasonCode === 'admission_not_valid');
    Event::assertNotDispatched(AdmissionCheckedIn::class);

    $sentence = CheckInRejectionReason::from((string) $checkIn->reason_code)->consoleSentence();
    expect($sentence)->not->toBe('')
        ->and($sentence)->not->toBe('admission_not_valid')
        ->and($sentence)->toEndWith('.');

    // Every reason has one, so the dialog never shows a code.
    foreach (CheckInRejectionReason::cases() as $reason) {
        expect($reason->consoleSentence())->not->toBe('')->toEndWith('.');
    }
});

it('refuses re-entry under once_only after a check-out, as the door does', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand, ReentryPolicy::OnceOnly);
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();

    $in = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');
    $out = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'out');
    $again = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

    // Derivation: the first `in` has no prior accepted entry, so once_only
    // admits; `out` runs no re-entry check; the second `in` is allowed past
    // the console's own rule (the last accepted row is `out`) and then finds
    // one prior accepted entry, which once_only refuses —
    // `reentry_not_permitted`, the reason `EntranceDayAndReentryTest` gets.
    expect($in->result)->toBe(CheckInResult::Accepted)
        ->and($out->result)->toBe(CheckInResult::Accepted)
        ->and($again->result)->toBe(CheckInResult::Rejected)
        ->and($again->reason_code)->toBe(CheckInRejectionReason::ReentryNotPermitted->value);
});

it('refuses a second check-in while in, and a check-out while never in, without writing a row', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $inside = Admission::factory()->create(['event_id' => $event['eventId']]);
    $neverIn = Admission::factory()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();

    app(CheckInFromConsole::class)->handle($inside, $event['eventId'], $actorId, 'in');

    // Derivation: one row so far, the accepted `in` above.
    expect(CheckIn::query()->count())->toBe(1);

    Event::fake([ConsoleCheckInRecorded::class, AdmissionCheckedIn::class, CheckInRejected::class]);

    $alreadyIn = consoleCheckInRefusal(fn () => app(CheckInFromConsole::class)->handle($inside, $event['eventId'], $actorId, 'in'));
    $notIn = consoleCheckInRefusal(fn () => app(CheckInFromConsole::class)->handle($neverIn, $event['eventId'], $actorId, 'out'));

    expect($alreadyIn?->reason)->toBe(ConsoleCheckInRefusedException::ALREADY_IN)
        ->and($alreadyIn?->getMessage())->toBe('This ticket is already checked in.')
        ->and($notIn?->reason)->toBe(ConsoleCheckInRefusedException::NOT_IN);

    // Derivation: 1 before, 1 after — neither refusal wrote anything.
    expect(CheckIn::query()->count())->toBe(1);

    Event::assertNotDispatched(ConsoleCheckInRecorded::class);
    Event::assertNotDispatched(AdmissionCheckedIn::class);
    Event::assertNotDispatched(CheckInRejected::class);
});

it('refuses a ticket of another event through this event\'s console, and writes nothing', function (): void {
    $brand = $this->withBrand();
    $thisEvent = consoleCheckInEvent($brand);
    $otherEvent = consoleCheckInEvent($brand);
    $otherTicket = Admission::factory()->create(['event_id' => $otherEvent['eventId']]);
    Event::fake([ConsoleCheckInRecorded::class, AdmissionCheckedIn::class, CheckInRejected::class]);

    $refusal = consoleCheckInRefusal(fn () => app(CheckInFromConsole::class)->handle($otherTicket, $thisEvent['eventId'], (string) Str::ulid(), 'in'));

    // Derivation: the admission is re-read by this event's id and finds
    // nothing — the ticket's event is the other one — so it is refused with
    // the door's `wrong_event` sentence before any row or checkpoint exists.
    expect($refusal?->reason)->toBe(ConsoleCheckInRefusedException::WRONG_EVENT)
        ->and($refusal?->getMessage())->toBe(CheckInRejectionReason::WrongEvent->consoleSentence())
        ->and(CheckIn::query()->count())->toBe(0)
        ->and(Checkpoint::query()->count())->toBe(0)
        ->and($otherTicket->fresh()->state)->toBe(AdmissionState::Issued);

    Event::assertNotDispatched(ConsoleCheckInRecorded::class);
    Event::assertNotDispatched(AdmissionCheckedIn::class);
    Event::assertNotDispatched(CheckInRejected::class);
});

it('dispatches ConsoleCheckInRecorded only once the outermost transaction commits', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();
    Event::fake([ConsoleCheckInRecorded::class]);

    $checkIn = DB::transaction(function () use ($admission, $event, $actorId): CheckIn {
        $checkIn = app(CheckInFromConsole::class)->handle($admission, $event['eventId'], $actorId, 'in');

        // Still inside a caller's transaction: the row exists, the
        // announcement does not.
        Event::assertNotDispatched(ConsoleCheckInRecorded::class);

        return $checkIn;
    });

    Event::assertDispatchedTimes(ConsoleCheckInRecorded::class, 1);
    Event::assertDispatched(ConsoleCheckInRecorded::class, fn (ConsoleCheckInRecorded $recorded): bool => $recorded->checkInId === $checkIn->id
        && $recorded->admissionId === $admission->id
        && $recorded->eventId === $event['eventId']
        && $recorded->brandId === $brand->id
        && $recorded->actorUserId === $actorId
        && $recorded->direction === 'in'
        && $recorded->result === 'accepted'
        && $recorded->reasonCode === null);
});

it('audits every console check-in and check-out with the admin, and nothing for a refusal', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $revoked = Admission::factory()->revoked()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();
    $console = app(CheckInFromConsole::class);

    $in = $console->handle($admission, $event['eventId'], $actorId, 'in');
    $out = $console->handle($admission, $event['eventId'], $actorId, 'out');
    $rejected = $console->handle($revoked, $event['eventId'], $actorId, 'in');
    consoleCheckInRefusal(fn () => $console->handle($admission, $event['eventId'], $actorId, 'out'));

    $entries = AuditLogEntry::query()
        ->where('action', 'like', 'admission.console-%')
        ->orderBy('occurred_at')
        ->orderBy('id')
        ->get();

    // Derivation: three rows were written — accepted in, accepted out, and
    // the revoked ticket's rejection — so three entries; the fourth attempt
    // (`out` while already out) was refused before any write and adds none.
    expect($entries)->toHaveCount(3);

    $byCheckIn = $entries->keyBy(fn (AuditLogEntry $entry): string => (string) $entry->context['check_in_id']);

    expect($byCheckIn[$in->id]->action)->toBe('admission.console-check-in')
        ->and($byCheckIn[$in->id]->subject_id)->toBe($admission->id)
        ->and($byCheckIn[$in->id]->brand_id)->toBe($brand->id)
        ->and($byCheckIn[$in->id]->actor_user_id)->toBe($actorId)
        ->and($byCheckIn[$in->id]->context['result'])->toBe('accepted')
        ->and($byCheckIn[$in->id]->context['reason_code'])->toBeNull()
        ->and($byCheckIn[$out->id]->action)->toBe('admission.console-check-out')
        ->and($byCheckIn[$out->id]->context['result'])->toBe('accepted')
        ->and($byCheckIn[$rejected->id]->action)->toBe('admission.console-check-in')
        ->and($byCheckIn[$rejected->id]->subject_id)->toBe($revoked->id)
        ->and($byCheckIn[$rejected->id]->context['result'])->toBe('rejected')
        ->and($byCheckIn[$rejected->id]->context['reason_code'])->toBe('admission_not_valid');
});

it('counts console check-ins in, out and refused, and not a refusal that wrote nothing', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $admission = Admission::factory()->create(['event_id' => $event['eventId']]);
    $revoked = Admission::factory()->revoked()->create(['event_id' => $event['eventId']]);
    $actorId = (string) Str::ulid();
    $console = app(CheckInFromConsole::class);

    $console->handle($admission, $event['eventId'], $actorId, 'in');
    $console->handle($admission, $event['eventId'], $actorId, 'out');
    $console->handle($revoked, $event['eventId'], $actorId, 'in');
    consoleCheckInRefusal(fn () => $console->handle($admission, $event['eventId'], $actorId, 'out'));

    // Derivation: one accepted `in`, one accepted `out`, one core rejection
    // (`refused`); the last attempt threw before writing and raised no
    // ConsoleCheckInRecorded, so `refused` stays at 1.
    $slug = BrandSlugs::of($brand->id);
    expect(consoleCheckInMetrics())
        ->toContain('konventix_admission_console_check_ins_total{brand="'.$slug.'",outcome="in"} 1')
        ->toContain('konventix_admission_console_check_ins_total{brand="'.$slug.'",outcome="out"} 1')
        ->toContain('konventix_admission_console_check_ins_total{brand="'.$slug.'",outcome="refused"} 1');
});

it('checks a crew badge in from the console without a registration behind it (#333)', function (): void {
    $brand = $this->withBrand();
    $event = consoleCheckInEvent($brand);
    $holder = ticketPerson();
    $crew = app(IssueCrewAdmission::class)->handle((string) Str::ulid(), $event['eventId'], $brand->id, $holder->id, 'crew');

    $seen = [];
    Event::listen(AdmissionCheckedIn::class, function (AdmissionCheckedIn $checkedIn) use (&$seen): void {
        $seen[] = $checkedIn;
    });

    $checkIn = app(CheckInFromConsole::class)->handle($crew, $event['eventId'], (string) Str::ulid(), 'in');

    // Derivation: a crew admission (D-STF-01) is assigned from birth with an
    // open window and no registration. Every check passes, so the row is
    // accepted with a null registration — which `AdmissionCheckedIn` can now
    // carry instead of throwing a TypeError under strict_types.
    expect($crew->registration_id)->toBeNull()
        ->and($checkIn->result)->toBe(CheckInResult::Accepted)
        ->and($checkIn->fresh()->registration_id)->toBeNull()
        ->and($seen)->toHaveCount(1)
        ->and($seen[0]->registrationId)->toBeNull()
        ->and(EventPresence::query()->where('user_id', $holder->id)->sole()->hub_access)->toBeTrue();
});

it('accepts a crew badge scanned at the door without an error (#333)', function (): void {
    $brand = $this->withBrand();
    ticketSigningKey($brand);
    $lane = entranceTestLane();
    $crew = app(IssueCrewAdmission::class)->handle((string) Str::ulid(), $lane['eventId'], $brand->id, (string) Str::ulid(), 'crew');

    $this->postJson('/api/entrance/scans', ['scans' => [[
        'token' => app(IssueAdmissionToken::class)->current($crew),
        'client_scan_id' => 'crew-door-1',
    ]]], entranceTestHeaders($lane['token']))
        ->assertOk()
        ->assertJsonPath('accepted', 1)
        ->assertJsonPath('rejected', 0);

    // Derivation: the lane's event has no projected profile, so it is not
    // day-scoped and re-entry is unlimited; the crew badge is assigned with
    // an open window. Accepted — and before #333 this was a 500.
    $row = CheckIn::query()->where('client_scan_id', 'crew-door-1')->sole();

    expect($row->result)->toBe(CheckInResult::Accepted)
        ->and($row->registration_id)->toBeNull()
        ->and($row->admission_id)->toBe($crew->id);
});
