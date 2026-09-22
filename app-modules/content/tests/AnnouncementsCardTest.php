<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Konventix\Content\Data\AnnouncementsCardData;
use Konventix\Content\Enums\ContentType;
use Konventix\Content\Models\ContentEntry;
use Konventix\Identity\Models\User;
use Konventix\Platform\Hub\HubRegistry;

/**
 * D-HUB-05: what the organizers said today, on the hub. Three entries and
 * a way to the rest — the card is a teaser of `/news`, not a second feed,
 * so it reuses `scopeLive()` rather than deciding publication again.
 *
 * `published()`, not a `live()` factory state: `live()` is the query scope
 * (state *and* date), and the factory publishes an hour ago.
 */
it('lists the latest three live announcements of the active event', function (): void {
    $brand = $this->withBrand();
    $eventId = (string) Str::ulid();

    foreach (range(1, 4) as $index) {
        ContentEntry::factory()->published()->create([
            'event_id' => $eventId,
            'type' => ContentType::Announcement,
            'title' => "Ansage {$index}",
            'published_at' => now()->subHours(5 - $index),
        ]);
    }

    ContentEntry::factory()->published()->create(['event_id' => (string) Str::ulid()]);   // other event
    ContentEntry::factory()->create(['event_id' => $eventId, 'type' => ContentType::Announcement]);   // draft
    ContentEntry::factory()->published()->create(['event_id' => $eventId, 'type' => ContentType::Page]);   // not a feed item

    $user = User::factory()->create();
    $card = app(HubRegistry::class)->all()->first(fn ($item): bool => $item->key === 'content.announcements');
    $payload = ($card->payload)(hubActiveEventFor($brand, $user, $eventId, (string) Str::ulid()), $user);

    expect($payload)->toBeInstanceOf(AnnouncementsCardData::class)
        ->and($payload->entries)->toHaveCount(3)
        ->and($payload->entries[0]->title)->toBe('Ansage 4')
        ->and($payload->entries[0]->url)->toStartWith('/news/')
        ->and($payload->newsUrl)->toBe('/news');
});

it('says nothing rather than something else when the event has no entries', function (): void {
    $brand = $this->withBrand();
    $user = User::factory()->create();

    $card = app(HubRegistry::class)->all()->first(fn ($item): bool => $item->key === 'content.announcements');
    $payload = ($card->payload)(hubActiveEventFor($brand, $user, (string) Str::ulid(), (string) Str::ulid()), $user);

    expect($payload->entries)->toBe([]);
});
