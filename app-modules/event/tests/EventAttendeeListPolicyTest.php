<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Validation\ValidationException;
use Konventix\Event\Actions\CreateEvent;
use Konventix\Event\Actions\UpdateEventDetails;
use Konventix\Event\Data\EventData;
use Konventix\Event\Enums\AttendeeListVisibility;
use Konventix\Event\Enums\EventVisibility;
use Konventix\Event\Enums\ReentryPolicy;
use Konventix\Event\Events\EventAttendeeListPolicyChanged;
use Konventix\Event\Models\Event;

/**
 * ADR-0064 §6: the per-event attendee-list switch travels as a contract
 * event in the shape of `EventReentryPolicyChanged` — the complete current
 * value, at creation and on every change — because Registration renders the
 * list from its own projection and may not read `events` (ADR-0004).
 *
 * Helper prefixed: Pest helpers are process-global (.ai/rules/tests.md).
 */
function attendeeListEventData(AttendeeListVisibility $visibility = AttendeeListVisibility::Off, string $slug = 'sxlan-2028'): EventData
{
    return new EventData(
        name: 'SXLan 2028',
        slug: $slug,
        description: null,
        startsAt: '2028-05-12 18:00:00',
        endsAt: '2028-05-14 14:00:00',
        timezone: 'Europe/Berlin',
        visibility: EventVisibility::Public,
        reentryPolicy: ReentryPolicy::Unlimited,
        attendeeListVisibility: $visibility,
    );
}

it('defaults a new event to no attendee list and says so at creation', function (): void {
    $brand = $this->withBrand();

    EventBus::fake([EventAttendeeListPolicyChanged::class]);

    $event = app(CreateEvent::class)->handle($brand->getKey(), attendeeListEventData());

    expect($event->attendee_list_visibility)->toBe(AttendeeListVisibility::Off);

    EventBus::assertDispatched(
        EventAttendeeListPolicyChanged::class,
        fn (EventAttendeeListPolicyChanged $e): bool => $e->eventId === $event->getKey()
            && $e->brandId === $brand->getKey()
            && $e->attendeeListVisibility === 'off',
    );
});

it('emits the complete current value when the switch changes', function (): void {
    $brand = $this->withBrand();
    $event = app(CreateEvent::class)->handle($brand->getKey(), attendeeListEventData());

    EventBus::fake([EventAttendeeListPolicyChanged::class]);

    app(UpdateEventDetails::class)->handle($event, attendeeListEventData(AttendeeListVisibility::Members));

    expect($event->fresh()->attendee_list_visibility)->toBe(AttendeeListVisibility::Members);

    EventBus::assertDispatchedTimes(EventAttendeeListPolicyChanged::class, 1);
    EventBus::assertDispatched(
        EventAttendeeListPolicyChanged::class,
        fn (EventAttendeeListPolicyChanged $e): bool => $e->attendeeListVisibility === 'members',
    );
});

it('stays silent on an update that leaves the switch alone', function (): void {
    $brand = $this->withBrand();
    $event = app(CreateEvent::class)->handle($brand->getKey(), attendeeListEventData(AttendeeListVisibility::Public));

    EventBus::fake([EventAttendeeListPolicyChanged::class]);

    app(UpdateEventDetails::class)->handle($event, attendeeListEventData(AttendeeListVisibility::Public, slug: 'renamed'));

    EventBus::assertNotDispatched(EventAttendeeListPolicyChanged::class);
});

it('refuses a value outside the enum on the form', function (): void {
    expect(fn () => EventData::validateAndCreate([
        ...attendeeListEventData()->toArray(),
        'attendeeListVisibility' => 'everyone',
    ]))->toThrow(ValidationException::class);
});

it('reads the stored switch back into the editor form', function (): void {
    $this->withBrand();

    $event = Event::factory()->create(['attendee_list_visibility' => AttendeeListVisibility::Members]);

    expect(EventData::fromModel($event)->attendeeListVisibility)->toBe(AttendeeListVisibility::Members);
});
