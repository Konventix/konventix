<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Actions\AssignVenue;
use Konventix\Event\Data\OpeningPeriodData;
use Konventix\Event\Data\VenuePlaceData;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;

/**
 * Task 18: the public venue page. A venue has no visibility of its own —
 * it is reachable exactly while one of the events assigned to it is
 * (`Venue::isPubliclyReachable()`), and every section renders only when
 * the operator filled it in.
 */
function venueLandingPublished(Venue $venue): Event
{
    $event = Event::factory()->published()->create();

    app(AssignVenue::class)->handle($event, $venue, isPrimary: true);

    return $event;
}

it('renders a venue reached through a published event, pictures primary first', function (): void {
    $brand = $this->withBrand();
    $first = MediaItem::factory()->create(['brand_id' => $brand->id, 'alt_text' => 'The foyer']);
    $second = MediaItem::factory()->create(['brand_id' => $brand->id, 'alt_text' => 'The hall']);

    $venue = Venue::factory()->create([
        'name' => 'Congress Centrum',
        'slug' => 'congress-centrum',
        'description' => 'A hall by the water.',
        'entry_latitude' => 53.5511,
        'entry_longitude' => 9.9937,
        'public_transport' => 'S1 to Landungsbrücken.',
        'seat_plan_reference' => 'Hall A, rows 1–24',
        'opening_times' => [new OpeningPeriodData(dayOfWeek: 1, opensAt: '09:00', closesAt: '18:00')],
        'parking_spots' => [new VenuePlaceData(label: 'Lot A', address: 'Hafenstr. 1', latitude: 53.55, longitude: 9.99)],
        'loading_zones' => [new VenuePlaceData(label: 'Ramp North')],
        'image_media_ids' => [$first->id, $second->id],
        'primary_media_id' => $second->id,
    ]);

    venueLandingPublished($venue);

    $this->get('/venues/congress-centrum')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('event/Venue')
            ->where('venue.name', 'Congress Centrum')
            ->where('venue.description', 'A hall by the water.')
            ->where('venue.seatPlanReference', 'Hall A, rows 1–24')
            ->where('venue.mapsUrl', fn (?string $url): bool => is_string($url)
                && str_contains($url, 'openstreetmap.org')
                && str_contains($url, '53.5511'))
            ->count('images', 2)
            // The primary comes first, whatever order the list is in.
            ->where('images.0.alt', 'The hall')
            ->where('images.1.alt', 'The foyer')
            ->count('openingTimes', 1)
            ->where('parkingSpots.0.label', 'Lot A')
            ->where('parkingSpots.0.mapsUrl', fn (?string $url): bool => is_string($url))
            // A place without coordinates gets no link rather than a pin
            // in the Gulf of Guinea.
            ->where('loadingZones.0.mapsUrl', null));
});

it('sends nothing for the attributes nobody filled in', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create([
        'slug' => 'bare-venue',
        'latitude' => null,
        'longitude' => null,
    ]);

    venueLandingPublished($venue);

    $this->get('/venues/bare-venue')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('venue.description', null)
            ->where('venue.publicTransport', null)
            ->where('venue.seatPlanReference', null)
            ->where('venue.mapsUrl', null)
            ->count('images', 0)
            ->count('openingTimes', 0)
            ->count('parkingSpots', 0)
            ->count('loadingZones', 0));
});

it('skips a picture whose media item no longer resolves', function (): void {
    $brand = $this->withBrand();
    $kept = MediaItem::factory()->create(['brand_id' => $brand->id]);

    $venue = Venue::factory()->create([
        'slug' => 'stale-pictures',
        'image_media_ids' => [(string) Str::ulid(), $kept->id],
        'primary_media_id' => $kept->id,
    ]);

    venueLandingPublished($venue);

    $this->get('/venues/stale-pictures')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('images', 1)
            ->where('images.0.url', fn (string $url): bool => str_contains($url, $kept->path)));
});

it('404s a venue no publicly visible event happens at', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create(['slug' => 'private-venue']);

    // Assigned to nothing at all.
    $this->get('/venues/private-venue')->assertNotFound();

    // Assigned to a draft event, which has no public page either.
    app(AssignVenue::class)->handle(Event::factory()->create(), $venue, isPrimary: true);

    $this->get('/venues/private-venue')->assertNotFound();
});

it('renders a venue whose only event is unlisted, the way that event does', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create(['slug' => 'unlisted-venue']);

    app(AssignVenue::class)->handle(Event::factory()->unlisted()->create(), $venue, isPrimary: true);

    $this->get('/venues/unlisted-venue')->assertOk();
});

it('404s a venue resolved on a different brand than the one it belongs to', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create(['slug' => 'wrong-brand-venue']);
    venueLandingPublished($venue);

    Brand::factory()->withDomain('other-venue-host.test')->create();

    $this->get('http://other-venue-host.test/venues/wrong-brand-venue')->assertNotFound();
});

it('404s an unknown slug', function (): void {
    $this->withBrand();

    $this->get('/venues/does-not-exist')->assertNotFound();
});

// The event page links each venue to its own page and wears its primary
// picture beside it.
it('links the venue from the event landing page and shows its primary picture', function (): void {
    $brand = $this->withBrand();
    $picture = MediaItem::factory()->create(['brand_id' => $brand->id, 'alt_text' => 'The hall']);

    $venue = Venue::factory()->create([
        'slug' => 'linked-venue',
        'image_media_ids' => [$picture->id],
        'primary_media_id' => $picture->id,
    ]);

    $event = venueLandingPublished($venue);

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('venues.0.slug', 'linked-venue')
            ->where('venues.0.imageAlt', 'The hall')
            ->where('venues.0.imageUrl', fn (?string $url): bool => is_string($url)
                && str_contains($url, $picture->path)));
});
