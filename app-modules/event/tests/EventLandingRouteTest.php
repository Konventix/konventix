<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Models\Event;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;

/**
 * SPEC.md "Event visibility": the public landing route resolves a published
 * event on its own brand's domain, an unlisted event reachable via direct
 * link regardless of lifecycle state, and 404s for anything else — draft
 * events with public visibility, and any event resolved on the wrong brand.
 */
it('renders the landing page for a published event on its own brand', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('event/Landing')
            ->where('event.name', $event->name)
            ->where('event.timezone', $event->timezone));
});

it('404s a draft event with public visibility', function (): void {
    $this->withBrand();

    $event = Event::factory()->create();

    $this->get('/events/'.$event->slug)->assertNotFound();
});

it('returns a draft event with unlisted visibility, since it is reachable by direct link', function (): void {
    $this->withBrand();

    $event = Event::factory()->unlisted()->create();

    $this->get('/events/'.$event->slug)->assertOk();
});

it('404s an event resolved on a different brand than the one it belongs to', function (): void {
    $this->withBrand(); // the default test brand, bound to localhost

    $event = Event::factory()->published()->create();

    Brand::factory()->withDomain('other-brand.test')->create();

    $this->get('http://other-brand.test/events/'.$event->slug)->assertNotFound();
});

it('404s an unknown slug', function (): void {
    $this->withBrand();

    $this->get('/events/does-not-exist')->assertNotFound();
});

// The hero (#292): the banner's URL and alt text through the media library,
// null without one — and null again once the picture is gone, so a deleted
// file never leaves a broken image on the public page.
it('hands the landing page the banner, and null when there is none or it no longer resolves', function (): void {
    $brand = $this->withBrand();
    $banner = MediaItem::factory()->create(['brand_id' => $brand->id, 'alt_text' => 'The hall']);

    $withBanner = Event::factory()->published()->create(['banner_media_id' => $banner->id]);
    $bare = Event::factory()->published()->create(['slug' => 'bare-event']);
    $stale = Event::factory()->published()->create(['slug' => 'stale-event', 'banner_media_id' => (string) Str::ulid()]);

    $this->get('/events/'.$withBanner->slug)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('event.bannerUrl', fn (?string $url): bool => is_string($url) && str_contains($url, $banner->path))
            ->where('event.bannerAlt', 'The hall'));

    $this->get('/events/'.$bare->slug)
        ->assertInertia(fn ($page) => $page->where('event.bannerUrl', null)->where('event.bannerAlt', null));

    $this->get('/events/'.$stale->slug)
        ->assertInertia(fn ($page) => $page->where('event.bannerUrl', null));
});

// #309: an event keeps the slug an older system published it under, and the
// old link answers 301 onto the canonical URL rather than serving the page
// twice. The canonical slug still renders; a slug nobody carries still 404s.
it('answers a legacy slug with a 301 onto the canonical slug, and the canonical slug with the page', function (): void {
    $this->withBrand();

    Event::factory()->published()->create(['slug' => 'sxlan-26', 'legacy_slug' => 'sxlan-2026']);

    $this->get('/events/sxlan-2026')
        ->assertStatus(301)
        ->assertRedirect('/events/sxlan-26');

    $this->get('/events/sxlan-26')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('event/Landing'));
});

it('keeps the query string on the legacy redirect', function (): void {
    $this->withBrand();

    Event::factory()->published()->create(['slug' => 'sxlan-26', 'legacy_slug' => 'sxlan-2026']);

    $this->get('/events/sxlan-2026?utm_source=newsletter')
        ->assertStatus(301)
        ->assertRedirect('/events/sxlan-26?utm_source=newsletter');
});

it('behaves as before for an event without a legacy slug', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create(['slug' => 'sxlan-26']);

    expect($event->legacy_slug)->toBeNull();

    $this->get('/events/sxlan-26')->assertOk();
    $this->get('/events/sxlan-2026')->assertNotFound();
});

it('404s a legacy slug whose event the landing page would not show, and one of another brand', function (): void {
    $this->withBrand();

    // A draft with public visibility: `show()` 404s it, so the redirect
    // must not reveal its canonical slug either.
    Event::factory()->create(['slug' => 'draft-26', 'legacy_slug' => 'draft-2026']);
    Event::factory()->published()->create(['slug' => 'sxlan-26', 'legacy_slug' => 'sxlan-2026']);

    Brand::factory()->withDomain('other-legacy.test')->create();

    $this->get('/events/draft-2026')->assertNotFound();
    $this->get('http://other-legacy.test/events/sxlan-2026')->assertNotFound();
});
