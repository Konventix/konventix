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
