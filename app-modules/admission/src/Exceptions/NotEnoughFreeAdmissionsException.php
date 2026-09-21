<?php

declare(strict_types=1);

namespace Konventix\Admission\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\RevokeUnitsForRegistration` when a partial
 * cancellation asks for more admissions than are free (D-REG-15).
 *
 * "Free" is Admission's own word and the whole reason this refusal exists:
 * an admission that somebody already holds, is sitting on a named seat, or
 * has walked through a door with is not a unit the buyer can hand back for
 * money. The message names how many *are* free, because the operator's next
 * move is to reduce by that number instead — or to take the holder or the
 * seat away first.
 *
 * The refusal is load-bearing for the money: this listener runs
 * synchronously inside `ReduceRegistrationQuantity`'s transaction, so
 * throwing here rolls the whole reduction back and Commerce's queued refund
 * is never pushed.
 */
final class NotEnoughFreeAdmissionsException extends RuntimeException
{
    public static function forRegistration(string $registrationId, int $requested, int $free): self
    {
        return new self(sprintf(
            'Registration [%s] has %d admission(s) that can still be given back, not %d — '
            .'the rest are held by a person, bound to a seat, or have been used at the door.',
            $registrationId,
            $free,
            $requested,
        ));
    }
}
