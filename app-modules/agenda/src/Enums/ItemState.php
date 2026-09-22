<?php

declare(strict_types=1);

namespace Konventix\Agenda\Enums;

/**
 * Where an agenda item stands in the published programme (design §4.2).
 *
 * `Draft` is the organizer's workbench — the item exists but the public page
 * does not show it. `Published` is on the page.
 *
 * D-AGD-08: `Cancelled` is a *state*, not a deletion. A cancelled item stays
 * on the public page, struck through and with its `cancelled_reason`, because
 * somebody who planned their afternoon around it needs to be told it is off —
 * a row that simply disappears tells them nothing. That is why
 * `AgendaItem::scopeVisible()` keeps cancelled items alongside published ones.
 */
enum ItemState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';
}
