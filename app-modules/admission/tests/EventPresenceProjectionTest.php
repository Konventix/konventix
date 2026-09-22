<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Listeners\ProjectPresenceOnAdmissionCheckedIn;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;

beforeEach(function (): void {
    $this->brand = $this->withBrand();
    $this->eventId = (string) Str::ulid();
    ticketEventSummary($this->brand, $this->eventId, ['timezone' => 'Europe/Berlin']);
    EntranceEventProfile::factory()->create([
        'event_id' => $this->eventId,
        'reentry_policy' => ReentryPolicy::OncePerDay,
        'days' => [
            ['day_id' => (string) Str::ulid(), 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag', 'day_ends_at' => '03:00'],
            ['day_id' => (string) Str::ulid(), 'date' => '2026-10-17', 'sequence' => 2, 'label' => 'Samstag', 'day_ends_at' => '02:00'],
        ],
    ]);
    $this->holder = ticketPerson();
    $this->admission = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => $this->holder->id]);
});

it('admits the holder on an accepted scan and emits ParticipantAdmitted once', function (): void {
    EventBus::fake([ParticipantAdmitted::class, ParticipantPresenceChanged::class]);

    event(presenceScan($this->admission));
    event(presenceScan($this->admission, 'in', '2026-10-16T21:00:00+02:00'));

    $row = EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail();
    expect($row->hub_access)->toBeTrue()
        ->and($row->last_direction)->toBe('in')
        ->and($row->day_label)->toBe('Freitag')
        ->and($row->window_ends_at?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-17 03:00');

    EventBus::assertDispatchedTimes(ParticipantAdmitted::class, 1);
    EventBus::assertDispatched(ParticipantAdmitted::class, fn (ParticipantAdmitted $e): bool => $e->userId === $this->holder->id
        && $e->eventName === 'Kon Fest 2026' && $e->dayLabel === 'Freitag' && $e->windowEndsAt !== null);
    // Direction did not change on the second scan.
    EventBus::assertDispatchedTimes(ParticipantPresenceChanged::class, 1);
});

it('flips the badge on a check-out without touching access', function (): void {
    event(presenceScan($this->admission));
    EventBus::fake([ParticipantPresenceChanged::class]);

    event(presenceScan($this->admission, 'out', '2026-10-16T22:00:00+02:00'));

    $row = EventPresence::query()->where('user_id', $this->holder->id)->firstOrFail();
    expect($row->last_direction)->toBe('out')->and($row->hub_access)->toBeTrue();
    EventBus::assertDispatched(ParticipantPresenceChanged::class, fn (ParticipantPresenceChanged $e): bool => $e->direction === 'out');
});

it('creates no row for an admission without a holder account', function (): void {
    $unclaimed = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => null]);

    event(presenceScan($unclaimed));

    expect(EventPresence::query()->count())->toBe(0);
});

it('re-admits on the next day with a new window', function (): void {
    event(presenceScan($this->admission));
    app(ProjectEventPresence::class)
        ->closeExpiredWindows(CarbonImmutable::parse('2026-10-17T03:01:00+02:00'));
    expect(EventPresence::query()->firstOrFail()->hub_access)->toBeFalse();

    EventBus::fake([ParticipantAdmitted::class]);
    event(presenceScan($this->admission, 'in', '2026-10-17T09:00:00+02:00'));

    $row = EventPresence::query()->firstOrFail();
    expect($row->hub_access)->toBeTrue()
        ->and($row->access_ended_reason)->toBeNull()
        ->and($row->day_label)->toBe('Samstag')
        ->and($row->window_ends_at?->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-18 02:00');
    EventBus::assertDispatchedTimes(ParticipantAdmitted::class, 1);
    EventBus::assertDispatched(ParticipantAdmitted::class, fn (ParticipantAdmitted $e): bool => $e->dayLabel === 'Samstag');
});

it('keeps one row when two admissions of the same holder are scanned in', function (): void {
    $second = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => $this->holder->id]);
    EventBus::fake([ParticipantAdmitted::class, ParticipantPresenceChanged::class]);

    $action = app(ProjectEventPresence::class);
    $action->checkIn($this->brand->id, $this->eventId, $this->admission->id, 'in', CarbonImmutable::parse('2026-10-16T20:00:00+02:00'));
    $action->checkIn($this->brand->id, $this->eventId, $second->id, 'in', CarbonImmutable::parse('2026-10-16T20:00:01+02:00'));

    expect(EventPresence::query()->count())->toBe(1)
        ->and(EventPresence::query()->firstOrFail()->admission_id)->toBe($second->id);
    EventBus::assertDispatchedTimes(ParticipantAdmitted::class, 1);
    EventBus::assertDispatchedTimes(ParticipantPresenceChanged::class, 1);
});

/**
 * The race the queued listener exists for: two lanes read "no row" at the
 * same time and the sibling job's INSERT lands first. The competing row is
 * slipped in right after this job's own stale SELECT — a query listener,
 * not a model hook, because the action wraps its INSERT in a savepoint and
 * a row created inside that would vanish with the rollback, which no
 * sibling worker's committed row ever does.
 */
it('falls back to the sibling row when a parallel scan won the insert', function (): void {
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if ($raced || ! str_contains($query->sql, 'from "admission_event_presence"')) {
            return;
        }
        $raced = true;
        EventPresence::factory()->create([
            'event_id' => $this->eventId,
            'user_id' => $this->holder->id,
            'admission_id' => $this->admission->id,
            'first_admitted_at' => CarbonImmutable::parse('2026-10-16T17:59:59Z'),
            'last_check_in_at' => CarbonImmutable::parse('2026-10-16T17:59:59Z'),
            'day_label' => 'Freitag',
        ]);
    });
    EventBus::fake([ParticipantAdmitted::class, ParticipantPresenceChanged::class]);

    $row = app(ProjectEventPresence::class)
        ->checkIn($this->brand->id, $this->eventId, $this->admission->id, 'in', CarbonImmutable::parse('2026-10-16T20:00:00+02:00'));

    expect($raced)->toBeTrue()
        ->and(EventPresence::query()->count())->toBe(1)
        ->and($row?->wasRecentlyCreated)->toBeFalse()
        ->and($row?->last_check_in_at?->toIso8601String())->toBe('2026-10-16T18:00:00+00:00')
        ->and($row?->first_admitted_at?->toIso8601String())->toBe('2026-10-16T17:59:59+00:00')
        ->and($row?->hub_access)->toBeTrue();
    // The sibling job already announced this holder; this one must not repeat it.
    EventBus::assertNotDispatched(ParticipantAdmitted::class);
    EventBus::assertNotDispatched(ParticipantPresenceChanged::class);
});

it('projects presence off the request and after the scan committed', function (): void {
    $listener = app(ProjectPresenceOnAdmissionCheckedIn::class);

    expect($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->afterCommit)->toBeTrue()
        ->and($listener->tries)->toBe(3);
});
