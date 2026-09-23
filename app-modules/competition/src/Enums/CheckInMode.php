<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * How a competition checks its entrants in (D-CMP-18, design §6).
 *
 * The column shipped with the lifecycle because the competition form owns it
 * (design §9); the behaviour below arrived with §6's own slice, together with
 * `CheckInEntrant` / `UndoCheckIn` and the door-presence read model.
 *
 * The mode decides two different things, and they are deliberately separate
 * questions: whether being checked in *matters at all* (`requiresCheckIn()` —
 * "ready" on every page, and which entrants bracket generation seeds), and
 * whether a **captain** may do it themselves (`allowsSelfService()`). The
 * **desk can always check a team in or undo it, whatever the mode** (§6), so
 * no method here ever answers "may the desk?" — that is the
 * `CheckInEntrants` capability's job and nothing else's.
 */
enum CheckInMode: string
{
    /** Every registered entrant counts as ready. */
    case None = 'none';

    /** The captain checks the team in during the window before `starts_at`. */
    case SelfService = 'self_service';

    /** Only the desk checks teams in. */
    case Desk = 'desk';

    /**
     * Whether an entrant has to be checked in before it counts as ready
     * (design §6): with `none`, `registered` is ready and there is nothing to
     * do; otherwise readiness means `checked_in`. Bracket generation asks the
     * same question — a competition that checks in seeds checked-in entrants
     * only (§7.5).
     */
    public function requiresCheckIn(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether a captain may check their own team in, on the participant page,
     * inside the window. Only `self_service` — `desk` is the mode whose whole
     * point is that a human at a table does it.
     */
    public function allowsSelfService(): bool
    {
        return $this === self::SelfService;
    }

    /** In the reader's locale (ADR-0068); the English is the catalog key. */
    public function label(): string
    {
        return match ($this) {
            self::None => __('No check-in'),
            self::SelfService => __('Self-service'),
            self::Desk => __('At the desk'),
        };
    }
}
