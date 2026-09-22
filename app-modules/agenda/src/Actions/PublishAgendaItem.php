<?php

declare(strict_types=1);

namespace Konventix\Agenda\Actions;

use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Events\AgendaItemPublished;
use Konventix\Agenda\Models\AgendaItem;

/**
 * Puts a programme entry on the public page (design §4.2, §7).
 *
 * Publishing twice is a no-op that emits nothing: the editor's button is
 * the kind a nervous organizer presses again, and every listener this
 * event will ever have — Messaging first — treats it as "tell people".
 * Telling them twice is the bug.
 *
 * Re-publishing something that was called off clears the old reason with
 * it; a live item carrying "Referent krank" would be read off the page as
 * gospel.
 */
final class PublishAgendaItem
{
    public function handle(AgendaItem $item): AgendaItem
    {
        if ($item->state === ItemState::Published) {
            return $item;
        }

        $item->fill([
            'state' => ItemState::Published,
            'cancelled_reason' => null,
        ])->save();

        event(new AgendaItemPublished(
            agendaItemId: $item->id,
            eventId: $item->event_id,
            brandId: $item->brand_id,
            title: $item->title,
            startsAt: $item->starts_at?->toIso8601String(),
            endsAt: $item->ends_at?->toIso8601String(),
        ));

        return $item;
    }
}
