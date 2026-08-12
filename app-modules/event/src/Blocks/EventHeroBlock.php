<?php

declare(strict_types=1);

namespace Konventix\Event\Blocks;

use Konventix\Event\Data\Blocks\EventHeroBlockData;
use Konventix\Event\Data\Blocks\EventHeroResolvedData;
use Konventix\Event\Models\Event;
use Konventix\Platform\Blocks\BlockType;
use Konventix\Platform\Blocks\ResolvesBlockProps;

/**
 * Event's own static-page block: a hero section built from a specific
 * event's wall-clock schedule and venue.
 *
 * This is ADR-0003's cross-module proof in block form — Platform never
 * learns Event exists, it only sees a `BlockType`. The stored payload is
 * just the event's ULID (module-map.md: reference foreign entities by ID,
 * never leak an Eloquent model); `resolve()` looks the event up with
 * Event's own model, inside Event, and hands back plain scalars.
 */
final class EventHeroBlock implements BlockType, ResolvesBlockProps
{
    public function key(): string
    {
        return 'event-hero';
    }

    public function module(): string
    {
        return 'event';
    }

    public function component(): string
    {
        return 'event/blocks/EventHeroBlock';
    }

    public function dataClass(): string
    {
        return EventHeroBlockData::class;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $payload = EventHeroBlockData::from($data);

        // BelongsToBrand's global scope already restricts this to the
        // current brand's events, so a stale or cross-brand event ULID
        // resolves to null rather than another brand's event.
        $event = Event::query()->with('venue')->find($payload->eventId);

        return EventHeroResolvedData::forEvent($event)->toArray();
    }
}
