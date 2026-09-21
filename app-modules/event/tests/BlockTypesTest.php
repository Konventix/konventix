<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Konventix\Event\Blocks\CountdownBlock;
use Konventix\Event\Blocks\EventHeroBlock;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Blocks\BlockRegistry;
use Konventix\Platform\Blocks\HasBlockEditor;
use Konventix\Platform\Blocks\ResolvesBlockProps;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\StaticPage;

/**
 * Event's `event-hero` and `countdown` static-page block types — ADR-0003's
 * cross-module proof: Event registers them into Platform's registry through
 * the same `registerBlockTypes()` hook Platform's own blocks use, and their
 * props are resolved server-side, inside Event, from Event's own model
 * (module-map.md "No cross-module Eloquent imports").
 */
it('registers event-hero and countdown at boot, both resolving props server-side', function (): void {
    $registry = app(BlockRegistry::class);
    $types = $registry->all();

    expect($types)->toHaveKeys(['event-hero', 'countdown']);
    expect($types['event-hero'])->toBeInstanceOf(ResolvesBlockProps::class);
    expect($types['event-hero']->module())->toBe('event');
    expect($types['countdown'])->toBeInstanceOf(ResolvesBlockProps::class);
    expect($types['countdown']->module())->toBe('event');
    expect(new EventHeroBlock)->toBeInstanceOf(HasBlockEditor::class);
    expect(new CountdownBlock)->toBeInstanceOf(HasBlockEditor::class);
});

it('resolves event-hero props from the event\'s wall-clock schedule and venue', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create(['city' => 'Hamburg']);
    $event = Event::factory()->forVenue($venue)->published()->create([
        'name' => 'SXLan 2026',
        'starts_at' => '2026-08-10 10:00:00',
        'ends_at' => '2026-08-12 18:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    $props = (new EventHeroBlock)->resolve(['eventId' => $event->getKey()]);

    expect($props)->toMatchArray([
        'found' => true,
        'name' => 'SXLan 2026',
        'startsAt' => '2026-08-10 10:00:00',
        'endsAt' => '2026-08-12 18:00:00',
        'timezone' => 'Europe/Berlin',
        'venueCity' => 'Hamburg',
        'slug' => $event->slug,
    ]);
});

it('resolves event-hero without a slug for a draft event, so the hero never links to a landing page that 404s', function (): void {
    $this->withBrand();

    $event = Event::factory()->create(['name' => 'Not yet announced']);

    expect($event->isVisibleOnLanding())->toBeFalse();

    $props = (new EventHeroBlock)->resolve(['eventId' => $event->getKey()]);

    expect($props['found'])->toBeTrue();
    expect($props['name'])->toBe('Not yet announced');
    expect($props['slug'])->toBeNull();
});

it('resolves event-hero to a graceful not-found shape when the event does not exist', function (): void {
    $this->withBrand();

    $props = (new EventHeroBlock)->resolve(['eventId' => (string) Str::ulid()]);

    expect($props['found'])->toBeFalse();
    expect($props['name'])->toBeNull();
    expect($props['slug'])->toBeNull();
});

it('resolves countdown props to the event\'s starting instant, not the raw wall-clock string', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create([
        'starts_at' => '2026-08-10 10:00:00',
        // CEST (UTC+2) in August.
        'timezone' => 'Europe/Berlin',
    ]);

    $props = (new CountdownBlock)->resolve(['eventId' => $event->getKey(), 'label' => 'Doors open in']);

    expect($props['found'])->toBeTrue();
    expect($props['label'])->toBe('Doors open in');
    expect($props['targetIso'])->toBe($event->startsAtInstant()->toIso8601String());
    // A real instant, unlike EventHeroBlock's raw wall-clock strings: its UTC
    // reading is offset from the naive "10:00" once converted through
    // Europe/Berlin's summer (CEST, UTC+2) offset (ADR-0027).
    expect(Carbon\Carbon::parse($props['targetIso'])->utc()->format('H:i'))->toBe('08:00');
});

it('does not resolve an event belonging to another brand', function (): void {
    $this->withBrand();
    $event = Event::factory()->published()->create();

    $otherBrand = Brand::factory()->withDomain('other-brand.test')->create();
    app(BrandContext::class)->set($otherBrand);

    $eventHeroProps = (new EventHeroBlock)->resolve(['eventId' => $event->getKey()]);
    $countdownProps = (new CountdownBlock)->resolve(['eventId' => $event->getKey()]);

    expect($eventHeroProps['found'])->toBeFalse();
    expect($eventHeroProps['slug'])->toBeNull();
    expect($countdownProps['found'])->toBeFalse();
});

it('renders event-hero and countdown blocks through the full static-page pipeline', function (): void {
    $this->withBrand();

    $event = Event::factory()->published()->create([
        'name' => 'Xpertise Con',
        'starts_at' => '2026-09-01 09:00:00',
        'timezone' => 'Europe/Berlin',
    ]);

    StaticPage::factory()->published()->create([
        'slug' => 'home',
        'blocks' => [
            ['type' => 'event-hero', 'data' => ['eventId' => $event->getKey()]],
            ['type' => 'countdown', 'data' => ['eventId' => $event->getKey(), 'label' => null]],
        ],
    ]);

    $this->get('/pages/home')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('blocks.0.component', 'event/blocks/EventHeroBlock')
            ->where('blocks.0.props.name', 'Xpertise Con')
            ->where('blocks.0.props.slug', $event->slug)
            ->where('blocks.1.component', 'event/blocks/CountdownBlock')
            ->where('blocks.1.props.targetIso', $event->startsAtInstant()->toIso8601String()));
});
