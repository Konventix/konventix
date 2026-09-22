<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Platform\Hub\HubLayoutResolver;

/**
 * Platform's copy of the operator's per-event hub layout (design §6) is a
 * projection of Event's `EventHubLayoutChanged` (ADR-0004), served from
 * cache and forgotten by the listener — so the second read after a change
 * sees the change, not the TTL.
 */
it('projects the layout and serves it from cache', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    event(new EventHubLayoutChanged($eventId, $brand->id, [['key' => 'a', 'visible' => true]], true));
    $first = app(HubLayoutResolver::class)->for($eventId);
    event(new EventHubLayoutChanged($eventId, $brand->id, [['key' => 'b', 'visible' => false]], false));
    $second = app(HubLayoutResolver::class)->for($eventId);

    expect($first['hubReplacesHome'])->toBeTrue()->and($second['cards'][0]['key'])->toBe('b')->and($second['hubReplacesHome'])->toBeFalse();
});

it('returns an empty layout for an event nobody composed', function (): void {
    $this->withBrand();
    expect(app(HubLayoutResolver::class)->for((string) Str::ulid()))->toBe(['cards' => [], 'hubReplacesHome' => false]);
});
