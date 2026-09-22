<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Str;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Admission\Events\AdmissionRevoked;
use Konventix\Admission\Events\ParticipantAdmissionReplaced;
use Konventix\Admission\Events\ParticipantHubAccessEnded;
use Konventix\Admission\Listeners\EndHubAccessOnAdmissionRevoked;
use Konventix\Admission\Listeners\EndHubAccessOnEventClosed;
use Konventix\Admission\Models\Admission;
use Konventix\Admission\Models\EntranceEventProfile;
use Konventix\Admission\Models\EventPresence;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;

beforeEach(function (): void {
    $this->brand = $this->withBrand();
    $this->eventId = (string) Str::ulid();
    ticketEventSummary($this->brand, $this->eventId);
    EntranceEventProfile::factory()->create(['event_id' => $this->eventId]);
    $this->admission = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => ticketPerson()->id]);
    event(presenceScan($this->admission));
});

it('ends everyone\'s access when the event concludes', function (): void {
    EventBus::fake([ParticipantHubAccessEnded::class]);

    event(new EventConcluded(eventId: $this->eventId, brandId: $this->brand->id, concludedAt: now()->toIso8601String()));

    $row = EventPresence::query()->firstOrFail();
    expect($row->hub_access)->toBeFalse()->and($row->access_ended_reason)->toBe(HubAccessEndReason::Concluded);
    EventBus::assertDispatched(ParticipantHubAccessEnded::class, fn (ParticipantHubAccessEnded $e): bool => $e->reason === 'concluded');
});

it('ends access with reason cancelled when the event is cancelled', function (): void {
    event(new EventCancelled(eventId: $this->eventId, brandId: $this->brand->id, reasonCode: 'venue_lost', cancelledAt: now()->toIso8601String()));

    expect(EventPresence::query()->firstOrFail()->access_ended_reason)->toBe(HubAccessEndReason::Cancelled);
});

it('ends only the revoked admission\'s access', function (): void {
    $other = Admission::factory()->create(['event_id' => $this->eventId, 'holder_user_id' => ticketPerson()->id]);
    event(presenceScan($other));

    event(new AdmissionRevoked(
        admissionId: $this->admission->id, registrationId: null, eventId: $this->eventId, brandId: $this->brand->id,
        revokedByUserId: null, reasonCode: 'refunded', revokedAt: now()->toIso8601String(),
    ));

    expect(EventPresence::query()->where('admission_id', $this->admission->id)->firstOrFail()->hub_access)->toBeFalse()
        ->and(EventPresence::query()->where('admission_id', $other->id)->firstOrFail()->hub_access)->toBeTrue();
});

// Controller ruling (progress.md, Task 5): a person can hold a crew badge
// and a bought ticket for the same event. Losing one of them is not
// losing the event — the row moves over to the admission that still
// stands and nobody is told their access ended. They *are* told the
// admission changed (`ParticipantAdmissionReplaced`), because consumers
// mirror that id: Platform's `platform_hub_access` carries it and
// Resources' hub link is built out of it, so a silent re-point would
// leave that link naming the revoked admission (fix round 1).
it('keeps access on a second valid admission and re-points the row at it', function (): void {
    $crew = Admission::factory()->create([
        'event_id' => $this->eventId,
        'holder_user_id' => $this->admission->holder_user_id,
        'registration_id' => null,
        'role' => 'crew',
    ]);
    $this->admission->forceFill(['state' => AdmissionState::Revoked])->save();
    EventBus::fake([ParticipantHubAccessEnded::class, ParticipantAdmissionReplaced::class]);

    event(new AdmissionRevoked(
        admissionId: $this->admission->id, registrationId: $this->admission->registration_id, eventId: $this->eventId, brandId: $this->brand->id,
        revokedByUserId: null, reasonCode: 'registration_cancelled', revokedAt: now()->toIso8601String(),
    ));

    $row = EventPresence::query()->where('user_id', $this->admission->holder_user_id)->firstOrFail();
    expect($row->hub_access)->toBeTrue()
        ->and($row->access_ended_reason)->toBeNull()
        ->and($row->admission_id)->toBe($crew->id);
    EventBus::assertNotDispatched(ParticipantHubAccessEnded::class);
    EventBus::assertDispatched(
        ParticipantAdmissionReplaced::class,
        fn (ParticipantAdmissionReplaced $e): bool => $e->admissionId === $crew->id
            && $e->userId === $this->admission->holder_user_id
            && $e->eventId === $this->eventId
            && $e->brandId === $this->brand->id,
    );
});

// The other admission must still stand: one already revoked earlier is
// no fallback, so the row ends like any single-admission holder's.
it('does not fall back on an admission that is itself revoked', function (): void {
    Admission::factory()->revoked()->create([
        'event_id' => $this->eventId,
        'holder_user_id' => $this->admission->holder_user_id,
    ]);
    $this->admission->forceFill(['state' => AdmissionState::Revoked])->save();
    EventBus::fake([ParticipantAdmissionReplaced::class]);

    event(new AdmissionRevoked(
        admissionId: $this->admission->id, registrationId: $this->admission->registration_id, eventId: $this->eventId, brandId: $this->brand->id,
        revokedByUserId: null, reasonCode: 'registration_cancelled', revokedAt: now()->toIso8601String(),
    ));

    EventBus::assertNotDispatched(ParticipantAdmissionReplaced::class);

    $row = EventPresence::query()->where('user_id', $this->admission->holder_user_id)->firstOrFail();
    expect($row->hub_access)->toBeFalse()
        ->and($row->access_ended_reason)->toBe(HubAccessEndReason::Revoked)
        ->and($row->admission_id)->toBe($this->admission->id);
});

it('does not re-end a row that already lost access', function (): void {
    event(new EventConcluded(eventId: $this->eventId, brandId: $this->brand->id, concludedAt: now()->toIso8601String()));
    EventBus::fake([ParticipantHubAccessEnded::class]);

    event(new EventConcluded(eventId: $this->eventId, brandId: $this->brand->id, concludedAt: now()->toIso8601String()));

    EventBus::assertNotDispatched(ParticipantHubAccessEnded::class);
});

// Both fire from inside an admin action's transaction (`ConcludeEvent`,
// `CancelEvent`, `RevokeAdmissionsForRegistration`) and a big event's
// sweep is thousands of rows — the same reasons the check-in projection
// is queued after commit (ADR-0033).
it('runs both end listeners queued, after commit, with three tries', function (): void {
    foreach ([EndHubAccessOnEventClosed::class, EndHubAccessOnAdmissionRevoked::class] as $class) {
        $listener = app($class);

        expect($listener)->toBeInstanceOf(ShouldQueue::class)
            ->and($listener->afterCommit)->toBeTrue()
            ->and($listener->tries)->toBe(3);
    }
});
