<?php

declare(strict_types=1);

namespace Konventix\Agenda\Enums;

/**
 * What a programme cell stands for (timetable design §4.5): an item the
 * organizer entered, or a competition the organizer selected, whose time
 * Competition owns (D-AGD-03).
 *
 * The page draws both the same way and tells them apart by this alone: a
 * block carries a small "Turnier" label and, while Competition is on for
 * the brand, a link to the tournament's own page.
 */
enum ProgrammeEntryKind: string
{
    case Item = 'item';
    case Competition = 'competition';
}
