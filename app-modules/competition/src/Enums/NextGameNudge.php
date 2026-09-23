<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * The one nudge a row of the hub's *Nächste Spiele* card carries (design
 * §10a). Derived from what the match already records, never stored, and it
 * enforces nothing: it is a hint on a card, not a gate.
 *
 * The cases are listed in precedence order — when several apply, the first
 * wins (`Hub\NextGamesCard::nudgeFor()`). The German label lives with the
 * card's component, like the public pages' other labels.
 */
enum NextGameNudge: string
{
    /** The sides disagree and the desk has not decided (§8.4). */
    case Disputed = 'disputed';

    /** The other side reported; the viewer's side has not answered (§8.2). */
    case Confirm = 'confirm';

    /** The viewer's side reported; the other side has not answered. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** Due or unscheduled, and the viewer's side may report (§8.2). */
    case Report = 'report';

    /** `scheduled_at` has passed, every side is there, and the desk records. */
    case Due = 'due';
}
