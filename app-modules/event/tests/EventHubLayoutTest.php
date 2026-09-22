<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventHubLayout;

/**
 * The operator composes the live hub per event (design §6): the admin page
 * lists Platform's catalogue, the save stores the layout as submitted and
 * emits the complete layout for Platform to project (ADR-0004). Merging
 * with the registry (order, unknown keys, new cards) is Platform's job at
 * render time and is tested there — here the layout is stored as-is.
 */
it('saves the layout and emits the complete layout', function (): void {
    [, $admin] = eventsAdminOperator(['platform.access-admin', 'event.update-event']);
    $this->withBrand();
    $event = Event::factory()->create();
    EventBus::fake([EventHubLayoutChanged::class]);

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}/hub", [
            'cards' => [['key' => 'content.announcements', 'visible' => false], ['key' => 'admission.my-ticket', 'visible' => true]],
            'hubReplacesHome' => true,
        ])
        ->assertRedirect();

    $layout = EventHubLayout::withoutBrandScope()->where('event_id', $event->id)->firstOrFail();
    expect($layout->hub_replaces_home)->toBeTrue()->and($layout->cards[0]['key'])->toBe('content.announcements');
    EventBus::assertDispatched(EventHubLayoutChanged::class, fn (EventHubLayoutChanged $e): bool => $e->hubReplacesHome && count($e->cards) === 2);
});

it('renders the hub page with the platform catalogue', function (): void {
    [, $admin] = eventsAdminOperator(['platform.access-admin', 'event.update-event']);
    $this->withBrand();
    $event = Event::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/hub")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/events/Hub')->has('catalogue'));
});
