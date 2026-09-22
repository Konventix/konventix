<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Konventix\Event\Data\HubLayoutEntryData;
use Konventix\Event\Data\UpdateHubLayoutData;
use Konventix\Event\Events\EventHubLayoutChanged;
use Konventix\Event\Models\Event;
use Konventix\Event\Models\EventHubLayout;

/**
 * Replaces an event's live hub layout (design §6) and tells Platform. One
 * row per event, upserted by `event_id` — the layout is a whole, so the
 * second save overwrites the first rather than merging into it.
 *
 * The lookup escapes BrandScope by event id: this runs on the operator
 * admin host, where the scope hardens to zero rows (ADR-0029), so a scoped
 * read would miss the existing row and die on `unique(event_id)`. On
 * create the brand is the event's own, set through `forceFill()` because
 * `brand_id` is never fillable and the `BelongsToBrand` hook has no ambient
 * brand to stamp here.
 */
final class SaveEventHubLayout
{
    public function handle(Event $event, UpdateHubLayoutData $data): EventHubLayout
    {
        $cards = array_values(array_map(
            fn (HubLayoutEntryData $card): array => ['key' => $card->key, 'visible' => $card->visible],
            $data->cards,
        ));

        $layout = EventHubLayout::withoutBrandScope()->where('event_id', $event->id)->first()
            ?? (new EventHubLayout)->forceFill(['brand_id' => $event->brand_id]);

        $layout->fill([
            'event_id' => $event->id,
            'cards' => $cards,
            'hub_replaces_home' => $data->hubReplacesHome,
        ])->save();

        event(new EventHubLayoutChanged(
            eventId: $event->id,
            brandId: $event->brand_id,
            cards: $cards,
            hubReplacesHome: $data->hubReplacesHome,
        ));

        return $layout;
    }
}
