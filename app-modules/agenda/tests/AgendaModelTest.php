<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Models\AgendaItem;
use Konventix\Agenda\Models\Location;
use Konventix\Agenda\Models\Track;

it('keeps track and location names unique per event', function (): void {
    $this->withBrand();
    $eventId = (string) Str::ulid();

    Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne']);
    Location::factory()->create(['event_id' => $eventId, 'name' => 'Halle 3']);

    expect(fn () => Track::factory()->create(['event_id' => $eventId, 'name' => 'Bühne']))
        ->toThrow(QueryException::class);
});

it('refuses an item with neither a start nor an end', function (): void {
    $this->withBrand();

    expect(fn () => AgendaItem::factory()->create(['starts_at' => null, 'ends_at' => null]))
        ->toThrow(QueryException::class);
});

it('allows an item with only a start, and one with only an end', function (): void {
    $this->withBrand();

    $startOnly = AgendaItem::factory()->create(['starts_at' => now(), 'ends_at' => null]);
    $endOnly = AgendaItem::factory()->create(['starts_at' => null, 'ends_at' => now()->addHour()]);

    expect($startOnly->fresh()->ends_at)->toBeNull()
        ->and($endOnly->fresh()->starts_at)->toBeNull()
        ->and($startOnly->fresh()->state)->toBe(ItemState::Draft);
});

it('leaves items placeless when their track or location goes away', function (): void {
    $this->withBrand();
    $track = Track::factory()->create();
    $location = Location::factory()->create();
    $item = AgendaItem::factory()->create(['track_id' => $track->id, 'location_id' => $location->id]);

    $track->delete();
    $location->delete();

    expect($item->fresh()->track_id)->toBeNull()
        ->and($item->fresh()->location_id)->toBeNull();
});
