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
 *
 * The console host is in the **URL**, not in `withServerVariables()`:
 * `MakesHttpRequests::prepareUrlForRequest()` runs the path through `url()`
 * first, so Symfony's `Request::create()` always finds a host in the URI and
 * overwrites `HTTP_HOST` with `localhost` — the server variable never
 * survives. `EnsureOperatorHost` then 404s every request here, which is what
 * pipeline 1942 found. Absolute URLs are how every other module's console
 * tests do it.
 */
it('refuses the editor without the capability and serves it with', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertInertia(fn ($page) => $page->component('admin/agenda/Edit')->has('tracks')->has('locations')->has('items'));
});

/**
 * The other half of the draft rule: the public page of a draft event 404s
 * (see `ProgrammePageTest`), and its editor does not — a programme is built
 * before the event is announced, which is the whole point of having a draft
 * state.
 */
it('serves the editor for an event that is still a draft', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand, publish: false);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/agenda/Edit'));
});

it('refuses the editor to an admin without ManageAgenda', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertForbidden();
});

it('saves tracks, locations and an item in one submit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator();

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda", [
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

    $console = $this->actingAs($admin);

    $console->post("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/items", [
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

    $console->post("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/items/{$item->getKey()}/publish")->assertRedirect();

    expect($item->refresh()->state)->toBe(ItemState::Published);

    $console->post("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/items/{$item->getKey()}/cancel", [
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
        ->post("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/items", [
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
        ->post("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/items/{$stranger->getKey()}/publish")
        ->assertNotFound();
});
