<?php

declare(strict_types=1);

use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;

/**
 * The programme editor (design §6): the surface that *does* need a
 * capability, on the operator console (ADR-0029).
 *
 * Every test creates its brand-owned rows before the first admin-host
 * request: that host resolves no brand and hardens `BrandScope` to nothing
 * (`.ai/rules/tests.md`).
 */
it('refuses the editor without the capability and serves it with', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->get("/admin/events/{$event->getKey()}/agenda")
        ->assertInertia(fn ($page) => $page->component('admin/agenda/Edit')->has('tracks')->has('locations')->has('items'));
});

it('refuses the editor to an admin without ManageAgenda', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->get("/admin/events/{$event->getKey()}/agenda")
        ->assertForbidden();
});

it('saves tracks, locations and an item in one submit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->put("/admin/events/{$event->getKey()}/agenda", [
            'tracks' => [['id' => null, 'name' => 'Bühne', 'position' => 1]],
            'locations' => [['id' => null, 'name' => 'Halle 3', 'venueId' => null, 'position' => 1]],
        ])
        ->assertRedirect();

    expect(Track::withoutBrandScope()->where('event_id', $event->getKey())->count())->toBe(1)
        ->and(Location::withoutBrandScope()->where('event_id', $event->getKey())->count())->toBe(1);
});

/**
 * An item is written through its own endpoint, because publishing and
 * calling off are separate acts with separate announcements behind them
 * (design §7) — `AgendaItemData` deliberately carries no `state`.
 */
it('adds, publishes and cancels one item through their own endpoints', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $console = $this->actingAs($admin)->withServerVariables(['HTTP_HOST' => $operator->admin_host]);

    $console->post("/admin/events/{$event->getKey()}/agenda/items", [
        'id' => null,
        'title' => 'Lötkurs',
        'description' => null,
        'trackId' => null,
        'locationId' => null,
        'locationNote' => 'Foyer',
        'speaker' => 'Ada',
        'startsAt' => '2026-11-20T15:00:00+01:00',
        'endsAt' => '2026-11-20T16:30:00+01:00',
    ])->assertRedirect();

    $item = AgendaItem::withoutBrandScope()->where('event_id', $event->getKey())->sole();

    expect($item->state)->toBe(ItemState::Draft);

    $console->post("/admin/events/{$event->getKey()}/agenda/items/{$item->getKey()}/publish")->assertRedirect();

    expect($item->refresh()->state)->toBe(ItemState::Published);

    $console->post("/admin/events/{$event->getKey()}/agenda/items/{$item->getKey()}/cancel", [
        'reason' => 'Referent krank',
    ])->assertRedirect();

    expect($item->refresh()->state)->toBe(ItemState::Cancelled)
        ->and($item->cancelled_reason)->toBe('Referent krank');
});

/**
 * D-AGD-04's "neither" is the one refused combination, and the form says so
 * before the check constraint has to.
 */
it('refuses an item with neither a start nor an end', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->post("/admin/events/{$event->getKey()}/agenda/items", [
            'title' => 'Irgendwann',
            'startsAt' => null,
            'endsAt' => null,
        ])
        ->assertSessionHasErrors(['startsAt', 'endsAt']);
});

/**
 * An id from another event is not this editor's to touch — the lookup is
 * scoped to the event *and* the brand, not just to the key.
 */
it('will not publish an item belonging to another event', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $stranger = AgendaItem::factory()->create(['title' => 'Fremd']);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->withServerVariables(['HTTP_HOST' => $operator->admin_host])
        ->post("/admin/events/{$event->getKey()}/agenda/items/{$stranger->getKey()}/publish")
        ->assertNotFound();
});
