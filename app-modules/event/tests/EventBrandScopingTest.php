<?php

declare(strict_types=1);

use Konventix\Event\Models\Event;
use Konventix\Event\Models\Venue;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;

/**
 * ADR-0010: Event and Venue are brand-owned via `BelongsToBrand`. These tests
 * exercise the mechanism against the real models rather than a throwaway
 * probe, since that is the property other modules will depend on.
 */
it('constrains events to the brand in context', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();
    $context = app(BrandContext::class);

    $context->set($first);
    Event::factory()->create(['name' => 'First brand event']);

    $context->set($second);
    Event::factory()->create(['name' => 'Second brand event']);

    expect(Event::query()->pluck('name')->all())->toBe(['Second brand event']);

    $context->set($first);
    expect(Event::query()->pluck('name')->all())->toBe(['First brand event']);
});

it('stamps brand_id on an event without being told', function (): void {
    $brand = $this->withBrand();

    $event = Event::factory()->create();

    expect($event->brand_id)->toBe($brand->getKey());
});

it('constrains venues to the brand in context', function (): void {
    $first = Brand::factory()->create();
    $second = Brand::factory()->create();
    $context = app(BrandContext::class);

    $context->set($first);
    Venue::factory()->create(['name' => 'First brand venue']);

    $context->set($second);
    Venue::factory()->create(['name' => 'Second brand venue']);

    expect(Venue::query()->pluck('name')->all())->toBe(['Second brand venue']);
});

it('allows an event to be assigned a venue from the same brand', function (): void {
    $this->withBrand();

    $venue = Venue::factory()->create();
    $event = Event::factory()->forVenue($venue)->create();

    expect($event->venues)->toHaveCount(1)
        ->and($event->primaryVenue()?->id)->toBe($venue->id);
});

it('leaves the venue list empty when no venue is assigned, since venues are optional (D-EVT-15)', function (): void {
    $this->withBrand();

    $event = Event::factory()->create();

    expect($event->venues)->toHaveCount(0)
        ->and($event->primaryVenue())->toBeNull();
});
