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
 * Cancelling something already cancelled changes nothing and emits
 * nothing, for the same reason publishing twice does not: the event is a
 * message to participants, and it is sent once.
 */
final class CancelAgendaItem
{
    public function handle(AgendaItem $item, ?string $reason): AgendaItem
    {
        if ($item->state === ItemState::Cancelled) {
            return $item;
        }

        $item->fill([
            'state' => ItemState::Cancelled,
            'cancelled_reason' => $reason,
        ])->save();

        event(new AgendaItemCancelled(
            agendaItemId: $item->id,
            eventId: $item->event_id,
            brandId: $item->brand_id,
            reason: $reason,
        ));

        return $item;
    }
}
