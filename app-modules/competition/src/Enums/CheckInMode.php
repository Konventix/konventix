<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * How a competition checks its entrants in (D-CMP-18, design §6).
 *
 * The column ships with the lifecycle because the competition form owns it
 * (design §9); the check-in Actions and the presence read model that read it
 * arrive with §6's own slice.
 */
enum CheckInMode: string
{
    /** Every registered entrant counts as ready. */
    case None = 'none';

    /** The captain checks the team in during the window before `starts_at`. */
    case SelfService = 'self_service';

    /** Only the desk checks teams in. */
    case Desk = 'desk';
}
