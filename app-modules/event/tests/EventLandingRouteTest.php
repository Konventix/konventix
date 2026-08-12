<?php

declare(strict_types=1);

use Konventix\Event\Models\Event;
use Konventix\Platform\Models\Brand;

/**
 * SPEC.md "Event visibility": the public landing route resolves a published
 * event on its own brand's domain, an unlisted event reachable via direct
 * link regardless of lifecycle state, and 404s for anything else — draft
 * events with public visibility, and any event resolved on the wrong brand.
 */
it('returns the event for a published event on its own brand', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create();

    $this->get('/events/'.$event->slug)
        ->assertOk()
        ->assertJson(['slug' => $event->slug, 'name' => $event->name]);
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
