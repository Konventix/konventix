<?php

declare(strict_types=1);

namespace Konventix\Agenda\Actions;

use Konventix\Agenda\Enums\ItemState;
use Konventix\Agenda\Events\AgendaItemCancelled;
use Konventix\Agenda\Models\AgendaItem;

/**
 * Calls a programme entry off (design §4.2, D-AGD-08).
 *
 * The row stays. A cancelled item is still on the public page, struck
 * through and carrying its reason, because somebody who planned an
 * afternoon around it has to be told — a row that simply disappears tells
 * them nothing.
 *
 * One rule, two halves: **always persist, emit only on Published →
 * Cancelled.**
 *
 * Persisting always is what lets an organizer correct the reason — "doch
 * nicht krank, verschoben auf Sonntag" over "Referent krank" — on an item
 * that is already off. An early return there would have written nothing
 * and still looked like a successful save to the caller.
 *
 * Emitting only on the transition out of `Published` is design §7's
 * trigger read literally: "a *published* item is cancelled". A draft
 * nobody could see is not news, and a consumer that mailed "Lötkurs 15:00
 * fällt aus" for it would be announcing an event that never existed —
 * the same reasoning that keeps `SaveAgendaItem` quiet when a draft moves.
 * Re-cancelling something already cancelled says it a second time, which
 * is the bug the guard on `PublishAgendaItem` avoids in the other
 * direction.
 */
final class CancelAgendaItem
{
    public function handle(AgendaItem $item, ?string $reason): AgendaItem
    {
        $wasPublished = $item->state === ItemState::Published;

        $item->fill([
            'state' => ItemState::Cancelled,
            'cancelled_reason' => $reason,
        ])->save();

        if ($wasPublished) {
            event(new AgendaItemCancelled(
                agendaItemId: $item->id,
                eventId: $item->event_id,
                brandId: $item->brand_id,
                reason: $reason,
            ));
        }

        return $item;
    }
}
