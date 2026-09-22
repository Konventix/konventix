<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Admission\Actions\ProjectEventPresence;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantPresenceChanged;
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
        'days' => [['day_id' => (string) Str::ulid(), 'date' => '2026-10-16', 'sequence' => 1, 'label' => 'Freitag', 'day_ends_at' => '03:00']],
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
    expect($row->hub_access)->toBeTrue()->and($row->access_ended_reason)->toBeNull();
    EventBus::assertDispatchedTimes(ParticipantAdmitted::class, 1);
});
