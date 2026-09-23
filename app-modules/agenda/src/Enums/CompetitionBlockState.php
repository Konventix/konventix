<?php

declare(strict_types=1);

namespace Konventix\Agenda\Enums;

/**
 * Whether a competition's block in the programme stands or is struck
 * (timetable design §4.5).
 *
 * Deliberately not Competition's `CompetitionStatus`: Agenda may not import
 * it (ADR-0004), and the programme does not care whether signup is open or
 * the bracket is running — only whether the tournament happens. A cancelled
 * competition stays on the page, struck through, for the same reason a
 * cancelled item does (D-AGD-08).
 */
enum CompetitionBlockState: string
{
    case Published = 'published';
    case Cancelled = 'cancelled';
}
