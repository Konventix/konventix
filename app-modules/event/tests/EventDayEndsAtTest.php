<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use Konventix\Event\Actions\SyncEventDays;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Models\Event;

/**
 * D-HUB-01: an event day may end after midnight (`day_ends_at`, `HH:MM`),
 * so a LAN day that runs until 03:00 keeps the live hub open until 03:00.
 * The value travels additively on `EventDaysChanged` into Admission's
 * `EntranceEventProfile.days` projection; null means 00:00 of the next day.
 */
it('stores a day end and carries it on EventDaysChanged', function (): void {
    $this->withBrand();
    $event = Event::factory()->create();
    EventBus::fake([EventDaysChanged::class]);

    app(SyncEventDays::class)->handle($event, [
        ['date' => '2026-10-16', 'label' => 'Freitag', 'day_ends_at' => '03:00'],
        ['date' => '2026-10-17', 'label' => null],
    ]);

    EventBus::assertDispatched(EventDaysChanged::class, function (EventDaysChanged $e): bool {
        return $e->days[0]['day_ends_at'] === '03:00'
            && $e->days[1]['day_ends_at'] === null;
    });
});

it('rejects a day end that is not HH:MM', function (): void {
    // The admin host has no brand; the controller escapes the scope itself.
    [$admin, $event, $payload] = eventsAdminEditable();

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [
            ...$payload,
            'days' => [['date' => '2026-10-16', 'label' => null, 'day_ends_at' => '3 Uhr']],
        ])
        ->assertSessionHasErrors('days.0.day_ends_at');
});
