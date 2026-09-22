<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Events\ParticipantAdmitted;
use Konventix\Admission\Events\ParticipantPresenceChanged;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\CheckIn;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Platform\Models\HubAccess;

/**
 * `admission:replay-presence` is what makes "rebuildable by replay" true
 * of Platform's `platform_hub_access` (ADR-0004) — the repair when a
 * projection fell behind, and, with `--seed`, the rollout step: 0.9.0
 * deployed while an event runs has no presence rows at all, so everybody
 * already inside the hall would be without a hub until they scanned again.
 */
beforeEach(function (): void {
    $this->brand = $this->withBrand();
    $this->eventId = (string) Str::ulid();
    ticketEventSummary($this->brand, $this->eventId);
    EntranceEventProfile::factory()->create(['event_id' => $this->eventId]);
    $this->holder = ticketPerson();
    $this->admission = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => $this->holder->id]);
});

it('re-emits the participant events for every row that still has access', function (): void {
    event(presenceScan($this->admission));

    $ended = EventPresence::factory()->create([
        'event_id' => $this->eventId,
        'hub_access' => false,
        'announced_hub_access' => false,
        'access_ended_reason' => HubAccessEndReason::DayEnded,
    ]);

    EventBus::fake([ParticipantAdmitted::class, ParticipantPresenceChanged::class]);

    $this->artisan('admission:replay-presence')
        ->expectsOutputToContain('Replayed 1 presence row(s).')
        ->assertSuccessful();

    EventBus::assertDispatchedTimes(ParticipantAdmitted::class, 1);
    EventBus::assertDispatched(ParticipantAdmitted::class, fn (ParticipantAdmitted $e): bool => $e->userId === $this->holder->id
        && $e->eventId === $this->eventId
        && $e->brandId === $this->brand->id
        && $e->admissionId === $this->admission->id
        && $e->eventName === 'Kon Fest 2026'
        && $e->eventSlug === 'kon-fest-2026');
    EventBus::assertDispatched(ParticipantPresenceChanged::class, fn (ParticipantPresenceChanged $e): bool => $e->userId === $this->holder->id && $e->direction === 'in');
    EventBus::assertNotDispatched(ParticipantAdmitted::class, fn (ParticipantAdmitted $e): bool => $e->userId === $ended->user_id);
});

it('replays only the named event', function (): void {
    event(presenceScan($this->admission));

    $otherEvent = (string) Str::ulid();
    ticketEventSummary($this->brand, $otherEvent, ['slug' => 'other-fest']);
    EntranceEventProfile::factory()->create(['event_id' => $otherEvent]);
    $other = Admission::factory()->create(['event_id' => $otherEvent, 'holder_user_id' => ticketPerson()->id]);
    event(presenceScan($other));

    EventBus::fake([ParticipantAdmitted::class]);

    $this->artisan('admission:replay-presence', ['--event' => $otherEvent])
        ->expectsOutputToContain('Replayed 1 presence row(s).')
        ->assertSuccessful();

    EventBus::assertDispatchedTimes(ParticipantAdmitted::class, 1);
    EventBus::assertDispatched(ParticipantAdmitted::class, fn (ParticipantAdmitted $e): bool => $e->eventId === $otherEvent);
});

it('rebuilds a hub for everybody already inside when the seed runs mid-event', function (): void {
    // What the rollout finds: scans on the ledger, no presence anywhere.
    $left = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => ticketPerson()->id]);
    $refused = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => ticketPerson()->id]);

    CheckIn::factory()->create(['admission_id' => $this->admission->id, 'scanned_at' => CarbonImmutable::parse('2026-10-16T18:00:00Z')]);
    CheckIn::factory()->create(['admission_id' => $left->id, 'scanned_at' => CarbonImmutable::parse('2026-10-16T18:10:00Z')]);
    CheckIn::factory()->create(['admission_id' => $left->id, 'direction' => 'out', 'scanned_at' => CarbonImmutable::parse('2026-10-16T19:00:00Z')]);
    CheckIn::factory()->rejected('signature_invalid')->create(['admission_id' => $refused->id, 'scanned_at' => CarbonImmutable::parse('2026-10-16T18:20:00Z')]);

    expect(EventPresence::query()->count())->toBe(0);

    $this->artisan('admission:replay-presence', ['--event' => $this->eventId, '--seed' => true])
        ->expectsOutputToContain('Seeded 3 check-in(s) into presence.')
        ->assertSuccessful();

    expect(EventPresence::query()->count())->toBe(2)
        ->and(EventPresence::query()->where('admission_id', $this->admission->id)->firstOrFail()->last_direction)->toBe('in')
        ->and(EventPresence::query()->where('admission_id', $left->id)->firstOrFail()->last_direction)->toBe('out')
        ->and(EventPresence::query()->where('admission_id', $refused->id)->exists())->toBeFalse();

    // Platform heard about it, which is the point: both of them have a hub,
    // and only the one still in the hall has the green dot.
    $access = HubAccess::withoutBrandScope()->get()->keyBy('admission_id');
    expect($access)->toHaveCount(2)
        ->and($access[$this->admission->id]->on_site)->toBeTrue()
        ->and($access[$left->id]->on_site)->toBeFalse()
        ->and($access[$this->admission->id]->event_name)->toBe('Kon Fest 2026');
});

it('refuses to seed without an event', function (): void {
    $this->artisan('admission:replay-presence', ['--seed' => true])
        ->expectsOutputToContain('--seed needs --event')
        ->assertFailed();
});
