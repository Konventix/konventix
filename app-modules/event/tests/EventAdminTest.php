<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event as EventFacade;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Events\EventDaysChanged;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventDay;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;

/**
 * ADR-0029: the event admin console — index and per-event dashboard — exists
 * only on the operator's admin host, behind `event.view-events`. The
 * dashboard composes its own header with contributions from other modules'
 * `event.admin.dashboard` UI slot (ADR-0003 enforcement point 3,
 * docs/decisions/0003). Registration and Admission are Core modules
 * (`gateable = false`), so their cards are always present — no Pennant
 * activation needed for this test to see them.
 */
it('404s the events index on a brand host', function (): void {
    eventsAdminOperator();

    $this->get('http://localhost/admin/events')->assertNotFound();
});

it('redirects an unauthenticated admin-host request to login', function (): void {
    eventsAdminOperator();

    $this->get('http://admin.eventsadmin.test/admin/events')
        ->assertRedirect('http://admin.eventsadmin.test/login');
});

it('403s an authenticated admin without the view-events capability', function (): void {
    [, $admin] = eventsAdminOperator(['platform.access-admin']);

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events')
        ->assertForbidden();
});

it('lists events across every brand on the index', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->withBrand();
    Event::factory()->create(['name' => 'Alpha Con']);

    $brandTwo = Brand::factory()->withDomain('event-admin-brand-two.test')->create();
    $this->withBrand($brandTwo);
    Event::factory()->create(['name' => 'Beta Con']);

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Index')
            ->has('events.data', 2));
});

it('shows the dashboard header and every core module\'s slot contribution', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->withBrand();
    $event = Event::factory()->create([
        'name' => 'Alpha Con',
        'starts_at' => '2027-06-15 10:00:00',
        'ends_at' => '2027-06-15 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Dashboard')
            ->where('event.id', $event->id)
            ->where('event.name', 'Alpha Con')
            ->where('event.startsAt', '2027-06-15 10:00')
            ->where('event.timezone', 'Europe/Berlin')
            ->where('slots', function (Collection $slots): bool {
                $components = $slots->pluck('component')->all();

                return in_array('registration/EventStatsCard', $components, true)
                    && in_array('admission/EventStatsCard', $components, true);
            }));
});

/**
 * ADR-0003's third enforcement point, for a link *out* of this page. The
 * dashboard offers a way into Agenda's programme editor, and that editor
 * does not exist for a brand with the module off — so the button must not
 * either. `SlotRegistry` cannot make this call: it gates on the ambient
 * brand, and the operator admin host resolves none (ADR-0029), so the
 * controller asks `isActiveForBrand()` with the event's own brand and sends
 * the answer as a prop.
 */
it('offers the programme editor only to a brand that has Agenda on', function (): void {
    [, $admin] = eventsAdminOperator();

    $brand = $this->withBrand();
    $event = Event::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('modules.agenda', false));

    $this->activateModule('agenda', $brand);

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('modules.agenda', true));
});

it('404s the dashboard for an unknown event ulid', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events/01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->assertNotFound();
});

// Regression: BrandScope hardens to zero rows on the admin host, so the day
// sync used to see no existing days, re-insert the submitted date, and die on
// unique(event_id, date) — while the edit page rendered an empty day list.
it('keeps event days across repeated saves on the admin host', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $payload['days'] = [['date' => '2027-06-15', 'label' => 'Day 1']];

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", $payload)
        ->assertRedirect();

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", $payload)
        ->assertRedirect();

    expect(EventDay::withoutBrandScope()->where('event_id', $event->id)->count())->toBe(1);

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Edit')
            ->has('days', 1)
            ->where('days.0.date', '2027-06-15')
            ->where('days.0.label', 'Day 1'));
});

it('emits EventDaysChanged with the full day set when saving on the admin host', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $payload['days'] = [
        ['date' => '2027-06-15', 'label' => null],
        ['date' => '2027-06-16', 'label' => 'Finals'],
    ];

    EventFacade::fake([EventDaysChanged::class]);

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", $payload)
        ->assertRedirect();

    EventFacade::assertDispatched(
        EventDaysChanged::class,
        fn (EventDaysChanged $changed): bool => count($changed->days) === 2
            && $changed->days[0]['date'] === '2027-06-15'
            && $changed->days[1]['date'] === '2027-06-16',
    );
});

it('lists assigned venues on the admin edit page', function (): void {
    [$admin, $event] = eventsAdminEditable();

    $venue = Venue::factory()->create(['brand_id' => $event->brand_id]);

    $this->actingAs($admin)
        ->post("http://admin.eventsadmin.test/admin/events/{$event->id}/venues", [
            'venue_id' => $venue->id,
            'is_primary' => true,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Edit')
            ->has('venues', 1)
            ->where('venues.0.id', $venue->id)
            ->where('venues.0.isPrimary', true));
});

it('defaults the brand filter to the console selection and lets the list clear it', function (): void {
    [, $admin] = eventsAdminOperator();

    $sxlan = $this->withBrand(Brand::factory()->create(['name' => 'SXLan']));
    Event::factory()->create(['name' => 'SXLan 2027']);

    $this->withBrand(Brand::factory()->create(['name' => 'Xpertise']));
    Event::factory()->create(['name' => 'Xpertise 2027']);

    $this->actingAs($admin)
        ->put('http://admin.eventsadmin.test/admin/selection', ['brandId' => $sxlan->getKey(), 'eventId' => null])
        ->assertRedirect();

    // The events list is what the event selector points into, so only the
    // brand half of the selection applies to it.
    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', $sxlan->getKey())
            ->count('events.data', 1)
            ->where('events.data.0.name', 'SXLan 2027'));

    $this->actingAs($admin)
        ->get('http://admin.eventsadmin.test/admin/events?brand=all')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.brand', null)
            ->count('events.data', 2));
});

// The event banner (#292): a media-library ULID owned by the event's brand,
// its usage recorded so the library refuses to delete a picture in use.
it("saves a banner from the brand's library, records its usage and hands the editor the candidates", function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();
    $banner = MediaItem::factory()->create(['brand_id' => $event->brand_id]);

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'bannerMediaId' => $banner->id])
        ->assertRedirect();

    expect($event->fresh()->banner_media_id)->toBe($banner->id)
        ->and(MediaUsage::withoutBrandScope()
            ->where('media_item_id', $banner->id)
            ->where('module', 'event')
            ->where('entity_type', 'event')
            ->where('entity_id', $event->id)
            ->exists())->toBeTrue();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/events/Edit')
            ->where('event.bannerMediaId', $banner->id)
            ->where('event.brandId', $event->brand_id)
            ->has('media', 1)
            ->where('media.0.id', $banner->id));
});

it("releases the old banner's usage when the banner changes or is cleared", function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();
    $first = MediaItem::factory()->create(['brand_id' => $event->brand_id]);
    $second = MediaItem::factory()->create(['brand_id' => $event->brand_id]);

    $usages = fn (string $mediaId): int => MediaUsage::withoutBrandScope()
        ->where('media_item_id', $mediaId)->where('entity_id', $event->id)->count();

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'bannerMediaId' => $first->id])
        ->assertRedirect();
    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'bannerMediaId' => $second->id])
        ->assertRedirect();

    expect($usages($first->id))->toBe(0)
        ->and($usages($second->id))->toBe(1);

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'bannerMediaId' => null])
        ->assertRedirect();

    expect($usages($second->id))->toBe(0)
        ->and($event->fresh()->banner_media_id)->toBeNull();
});

it('refuses a banner that belongs to another brand', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();
    $other = Brand::factory()->withDomain('other-banner.test')->create(['slug' => 'other-banner']);
    $foreign = MediaItem::factory()->create(['brand_id' => $other->id]);

    $this->actingAs($admin)
        ->from("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'bannerMediaId' => $foreign->id])
        ->assertSessionHasErrors('bannerMediaId');

    expect($event->fresh()->banner_media_id)->toBeNull();
});

/**
 * The shoutbox desk's button follows the same rule as the programme
 * editor's: `ModerationController` 404s for a brand with Community off, so
 * the dashboard offers the button only when the event's brand has it on.
 */
it('offers the shoutbox desk only to a brand that has Community on', function (): void {
    [, $admin] = eventsAdminOperator();

    $brand = $this->withBrand();
    $event = Event::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('modules.community', false));

    $this->activateModule('community', $brand);

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('modules.community', true));
});

// #309: the legacy slug is saved from the edit form and handed back to it;
// within the brand, slugs and legacy slugs are one namespace, so every
// collision between the two columns is a form error on the field that
// collides.
it('saves a legacy slug and hands it back to the editor', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'legacySlug' => 'sxlan-2026'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($event->fresh()->legacy_slug)->toBe('sxlan-2026');

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('event.legacySlug', 'sxlan-2026'));
});

// The fix round's other half: an old link pasted with capitals is stored
// lowercase, the form the redirect looks up — not refused by the
// lowercase-only regex, and not stored as typed where no lookup would find it.
it('stores a legacy slug typed with capitals in lowercase', function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $this->actingAs($admin)
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'legacySlug' => 'SXLAN-2026'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($event->fresh()->legacy_slug)->toBe('sxlan-2026');
});

it("refuses a legacy slug equal to another event's slug", function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();
    Event::factory()->create(['slug' => 'sxlan-26']);

    $this->actingAs($admin)
        ->from("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'legacySlug' => 'sxlan-26'])
        ->assertSessionHasErrors('legacySlug');

    expect($event->fresh()->legacy_slug)->toBeNull();
});

it("refuses a legacy slug equal to another event's legacy slug", function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();
    Event::factory()->create(['slug' => 'sxlan-26', 'legacy_slug' => 'sxlan-2026']);

    $this->actingAs($admin)
        ->from("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'legacySlug' => 'sxlan-2026'])
        ->assertSessionHasErrors('legacySlug');

    expect($event->fresh()->legacy_slug)->toBeNull();
});

it("refuses a legacy slug equal to the event's own slug", function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();

    $this->actingAs($admin)
        ->from("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'legacySlug' => $event->slug])
        ->assertSessionHasErrors('legacySlug');

    expect($event->fresh()->legacy_slug)->toBeNull();
});

it("refuses a slug equal to another event's legacy slug", function (): void {
    [$admin, $event, $payload] = eventsAdminEditable();
    Event::factory()->create(['slug' => 'sxlan-26', 'legacy_slug' => 'sxlan-2026']);

    $this->actingAs($admin)
        ->from("http://admin.eventsadmin.test/admin/events/{$event->id}/edit")
        ->put("http://admin.eventsadmin.test/admin/events/{$event->id}", [...$payload, 'slug' => 'sxlan-2026'])
        ->assertSessionHasErrors('slug');

    expect($event->fresh()->slug)->toBe($payload['slug']);
});

/**
 * Admission's attendee list is always there (Admission is Core), but only an
 * account holding `admission.check-in-attendees` may open it — so the
 * dashboard offers its button to that account and to nobody else, instead of
 * a link onto a 403 (#329, Task 4 review M4).
 */
it('hides the Attendees button from an account without admission.check-in-attendees', function (): void {
    [, $admin] = eventsAdminOperator();

    $this->withBrand();
    $event = Event::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.attendees', false));
});

it('offers the Attendees button to an account holding admission.check-in-attendees', function (): void {
    [, $admin] = eventsAdminOperator(['platform.access-admin', 'event.view-events', 'admission.check-in-attendees']);

    $this->withBrand();
    $event = Event::factory()->create();

    $this->actingAs($admin)
        ->get("http://admin.eventsadmin.test/admin/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.attendees', true));
});
