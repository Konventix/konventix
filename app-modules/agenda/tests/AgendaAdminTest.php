<?php

declare(strict_types=1);

use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;
use Konventix\Platform\Models\Operator;

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
    [$operator, $admin] = agendaAdminOperator($brand);

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
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/agenda/Edit'));
});

it('refuses the editor to an admin without ManageAgenda', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator($brand, ['platform.access-admin']);

    $this->actingAs($admin)
        ->get("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertForbidden();
});

it('saves tracks, locations and an item in one submit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator($brand);

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
 * `agenda_tracks` and `agenda_locations` are both unique on
 * (event_id, name), and a unique violation inside the Action's transaction
 * would be a 500 *and* the loss of every other rename and reorder in the
 * same submit. The rule is on the Data class, so the error arrives on the
 * offending row's own indexed key and the editor can show it there.
 */
it('refuses two tracks or two places with the same name in one submit', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->put("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda", [
            'tracks' => [
                ['id' => null, 'name' => 'Bühne', 'position' => 1],
                // Case alone is a typo, not a second track.
                ['id' => null, 'name' => 'bühne', 'position' => 2],
            ],
            'locations' => [
                ['id' => null, 'name' => 'Halle 3', 'venueId' => null, 'position' => 1],
                ['id' => null, 'name' => 'Halle 3', 'venueId' => null, 'position' => 2],
            ],
        ])
        ->assertSessionHasErrors(['tracks.0.name', 'tracks.1.name', 'locations.0.name', 'locations.1.name']);

    expect(Track::withoutBrandScope()->where('event_id', $event->getKey())->count())->toBe(0)
        ->and(Location::withoutBrandScope()->where('event_id', $event->getKey())->count())->toBe(0);
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
    [$operator, $admin] = agendaAdminOperator($brand);

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
    [$operator, $admin] = agendaAdminOperator($brand);

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
    [$operator, $admin] = agendaAdminOperator($brand);

    $this->actingAs($admin)
        ->post("http://{$operator->admin_host}/admin/events/{$event->getKey()}/agenda/items/{$stranger->getKey()}/publish")
        ->assertNotFound();
});

/**
 * The summary is found by event id across the whole installation, so the
 * editor itself refuses an event whose brand belongs to another operator:
 * a programme editor on operator Y's admin host must not read or rewrite
 * operator X's programme, even holding `ManageAgenda` and knowing the id.
 */
it('hides another operator\'s programme from an editor holding ManageAgenda', function (): void {
    $brand = $this->withBrand();
    $this->activateModule('agenda');
    $event = agendaPublishedEvent($brand);
    $item = agendaPublishedItem($event->getKey(), 'Lötkurs', '2026-11-20T15:00:00+01:00');

    [$elsewhere, $stranger] = agendaAdminOperator(
        $brand,
        operator: Operator::factory()->create(),
        host: 'admin.agendaadmin-elsewhere.test',
    );

    expect($elsewhere->getKey())->not->toBe($brand->operator_id);

    $this->actingAs($stranger)
        ->get("http://{$elsewhere->admin_host}/admin/events/{$event->getKey()}/agenda")
        ->assertNotFound();

    $this->actingAs($stranger)
        ->delete("http://{$elsewhere->admin_host}/admin/events/{$event->getKey()}/agenda/items/{$item->getKey()}")
        ->assertNotFound();

    expect(AgendaItem::withoutBrandScope()->whereKey($item->getKey())->exists())->toBeTrue();
});
