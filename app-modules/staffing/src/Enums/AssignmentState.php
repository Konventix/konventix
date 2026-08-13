<?php

declare(strict_types=1);

namespace Konventix\Staffing\Enums;

/**
 * Where an assignment stands (SPEC.md "Owns" — `Assignment`).
 *
 * `Offered` exists only on teams that opted into approval (D-STF-01): a
 * self-service sign-up on an open team goes straight to `Accepted`, because
 * making a volunteer wait for a rubber stamp is the friction self-service is
 * meant to remove.
 *
 * `Released` and `Declined` are deliberately distinct: declining is refusing
 * an offer that was never held, releasing is giving back a shift that was.
 * Only the second leaves a hole in a published roster someone has to fill.
 */
enum AssignmentState: string
{
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Released = 'released';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /**
     * Whether this state occupies one of a shift role's headcount slots.
     * Coverage counts these and nothing else.
     */
    public function occupiesSlot(): bool
    {
        return in_array($this, [self::Offered, self::Accepted, self::Completed], true);
    }
}
