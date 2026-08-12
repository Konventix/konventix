<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventFacade;
use Konventix\Event\Actions\ArchiveEvent;
use Konventix\Event\Actions\CancelEvent;
use Konventix\Event\Actions\ConcludeEvent;
use Konventix\Event\Actions\PublishEvent;
use Konventix\Event\Actions\RescheduleEvent;
use Konventix\Event\Actions\UnpublishEvent;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Events\EventUnpublished;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Exercises each lifecycle Action (SPEC.md "Event lifecycle") for both legal
 * and illegal starting states, and that each emits its contract event
 * (SPEC.md "Public contract") with exactly the documented payload.
 */
beforeEach(function (): void {
    $this->withBrand();
});

it('publishes a draft event and emits EventPublished', function (): void {
    EventFacade::fake([
        EventPublished::class,
        EventUnpublished::class,
        EventRescheduled::class,
        EventCancelled::class,
        EventConcluded::class,
        EventArchived::class,
    ]);

    $event = Event::factory()->create();

    $published = (new PublishEvent)->handle($event);

    expect($published->state)->toBe(EventLifecycleState::Published)
        ->and($published->published_at)->not->toBeNull();

    EventFacade::assertDispatched(
        EventPublished::class,
        fn (EventPublished $e): bool => $e->eventId === $event->id
            && $e->brandId === $event->brand_id
            && $e->publishedAt === $published->published_at->toIso8601String(),
    );
});

it('publishes an announced event', function (): void {
    $event = Event::factory()->announced()->create();

    $published = (new PublishEvent)->handle($event);

    expect($published->state)->toBe(EventLifecycleState::Published);
});

it('refuses to publish an event that is already published', function (): void {
    $event = Event::factory()->published()->create();

    (new PublishEvent)->handle($event);
})->throws(IllegalEventStateTransitionException::class);

it('unpublishes a published event back to announced and emits EventUnpublished', function (): void {
    EventFacade::fake([
        EventPublished::class,
        EventUnpublished::class,
        EventRescheduled::class,
        EventCancelled::class,
        EventConcluded::class,
        EventArchived::class,
    ]);

    $event = Event::factory()->published()->create();

    $unpublished = (new UnpublishEvent)->handle($event);

    expect($unpublished->state)->toBe(EventLifecycleState::Announced)
        ->and($unpublished->published_at)->toBeNull();

    EventFacade::assertDispatched(
        EventUnpublished::class,
        fn (EventUnpublished $e): bool => $e->eventId === $event->id && $e->brandId === $event->brand_id,
    );
});

it('refuses to unpublish a draft event', function (): void {
    $event = Event::factory()->create();

    (new UnpublishEvent)->handle($event);
})->throws(IllegalEventStateTransitionException::class);

it('reschedules an event and emits EventRescheduled with wall-clock values', function (): void {
    EventFacade::fake([
        EventPublished::class,
        EventUnpublished::class,
        EventRescheduled::class,
        EventCancelled::class,
        EventConcluded::class,
        EventArchived::class,
    ]);

    $event = Event::factory()->create();

    $rescheduled = (new RescheduleEvent)->handle(
        $event,
        '2027-03-01 09:00:00',
        '2027-03-01 18:00:00',
        'Europe/Berlin',
    );

    expect($rescheduled->starts_at->format('Y-m-d H:i:s'))->toBe('2027-03-01 09:00:00')
        ->and($rescheduled->ends_at->format('Y-m-d H:i:s'))->toBe('2027-03-01 18:00:00')
        ->and($rescheduled->timezone)->toBe('Europe/Berlin');

    EventFacade::assertDispatched(
        EventRescheduled::class,
        fn (EventRescheduled $e): bool => $e->eventId === $event->id
            && $e->startsAt === '2027-03-01 09:00:00'
            && $e->endsAt === '2027-03-01 18:00:00'
            && $e->timezone === 'Europe/Berlin',
    );
});

it('refuses to reschedule a concluded event', function (): void {
    $event = Event::factory()->concluded()->create();

    (new RescheduleEvent)->handle($event, '2027-03-01 09:00:00', '2027-03-01 18:00:00', 'Europe/Berlin');
})->throws(IllegalEventStateTransitionException::class);

it('refuses to reschedule an event to end before it starts', function (): void {
    $event = Event::factory()->create();

    (new RescheduleEvent)->handle($event, '2027-03-01 18:00:00', '2027-03-01 09:00:00', 'Europe/Berlin');
})->throws(InvalidArgumentException::class);

it('cancels a published event with a reason and emits EventCancelled', function (): void {
    EventFacade::fake([
        EventPublished::class,
        EventUnpublished::class,
        EventRescheduled::class,
        EventCancelled::class,
        EventConcluded::class,
        EventArchived::class,
    ]);

    $event = Event::factory()->published()->create();

    $cancelled = (new CancelEvent)->handle($event, 'venue-unavailable');

    expect($cancelled->state)->toBe(EventLifecycleState::Cancelled)
        ->and($cancelled->reason_code)->toBe('venue-unavailable')
        ->and($cancelled->cancelled_at)->not->toBeNull();

    EventFacade::assertDispatched(
        EventCancelled::class,
        fn (EventCancelled $e): bool => $e->eventId === $event->id
            && $e->brandId === $event->brand_id
            && $e->reasonCode === 'venue-unavailable'
            && $e->cancelledAt === $cancelled->cancelled_at->toIso8601String(),
    );
});

it('cancels a running event', function (): void {
    $event = Event::factory()->running()->create();

    $cancelled = (new CancelEvent)->handle($event, 'force-majeure');

    expect($cancelled->state)->toBe(EventLifecycleState::Cancelled);
});

it('refuses to cancel an archived event', function (): void {
    $event = Event::factory()->archived()->create();

    (new CancelEvent)->handle($event, 'too-late');
})->throws(IllegalEventStateTransitionException::class);

it('concludes a running event and emits EventConcluded', function (): void {
    EventFacade::fake([
        EventPublished::class,
        EventUnpublished::class,
        EventRescheduled::class,
        EventCancelled::class,
        EventConcluded::class,
        EventArchived::class,
    ]);

    $event = Event::factory()->running()->create();

    $concluded = (new ConcludeEvent)->handle($event);

    expect($concluded->state)->toBe(EventLifecycleState::Concluded)
        ->and($concluded->concluded_at)->not->toBeNull();

    EventFacade::assertDispatched(
        EventConcluded::class,
        fn (EventConcluded $e): bool => $e->eventId === $event->id
            && $e->brandId === $event->brand_id
            && $e->concludedAt === $concluded->concluded_at->toIso8601String(),
    );
});

it('refuses to conclude a published event that has not started running', function (): void {
    $event = Event::factory()->published()->create();

    (new ConcludeEvent)->handle($event);
})->throws(IllegalEventStateTransitionException::class);

it('archives a concluded event and emits EventArchived', function (): void {
    EventFacade::fake([
        EventPublished::class,
        EventUnpublished::class,
        EventRescheduled::class,
        EventCancelled::class,
        EventConcluded::class,
        EventArchived::class,
    ]);

    $event = Event::factory()->concluded()->create();

    $archived = (new ArchiveEvent)->handle($event);

    expect($archived->state)->toBe(EventLifecycleState::Archived)
        ->and($archived->archived_at)->not->toBeNull();

    EventFacade::assertDispatched(
        EventArchived::class,
        fn (EventArchived $e): bool => $e->eventId === $event->id
            && $e->brandId === $event->brand_id
            && $e->archivedAt === $archived->archived_at->toIso8601String(),
    );
});

it('archives a cancelled event', function (): void {
    $event = Event::factory()->cancelled()->create();

    $archived = (new ArchiveEvent)->handle($event);

    expect($archived->state)->toBe(EventLifecycleState::Archived);
});

it('refuses to archive a draft event', function (): void {
    $event = Event::factory()->create();

    (new ArchiveEvent)->handle($event);
})->throws(IllegalEventStateTransitionException::class);
