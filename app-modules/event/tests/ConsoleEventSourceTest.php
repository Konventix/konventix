<?php

declare(strict_types=1);

use Konventix\Event\Models\Event;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\ConsoleEventSources;

/**
 * Event contributes the console's event selector from its own provider
 * (ADR-0003: a module registers what it owns); Platform reads the registry
 * and imports nothing from here (ADR-0004).
 */
it('lists unarchived events for the console, newest first, narrowed by brand', function (): void {
    $sxlan = $this->withBrand();
    $early = Event::factory()->create(['name' => 'SXLan 2025', 'starts_at' => now()->subYear()]);
    $late = Event::factory()->create(['name' => 'SXLan 2026', 'starts_at' => now()->addMonth()]);
    Event::factory()->create(['name' => 'Gone', 'archived_at' => now()]);

    $xpertise = Brand::factory()->create(['slug' => 'xpertise']);
    app(BrandContext::class)->set($xpertise);
    $other = Event::factory()->create(['name' => 'Xpertise 2026']);

    $all = app(ConsoleEventSources::class)->eventsFor(null);
    $mine = app(ConsoleEventSources::class)->eventsFor($sxlan->getKey());

    expect(array_column($all, 'id'))->toContain($late->getKey(), $early->getKey(), $other->getKey())
        ->and(array_column($all, 'id'))->not->toContain(Event::withoutBrandScope()->where('name', 'Gone')->value('id'))
        ->and(array_column($mine, 'name'))->toBe(['SXLan 2026', 'SXLan 2025'])
        ->and($mine[0]['brandId'])->toBe($sxlan->getKey());
});
