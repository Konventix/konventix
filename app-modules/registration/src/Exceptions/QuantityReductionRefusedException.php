<?php

declare(strict_types=1);

namespace Konventix\Registration\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\ReduceRegistrationQuantity` when the partial
 * cancellation cannot be made (D-REG-15). Named constructors, because the
 * console turns each one into its own validation message on the reduce
 * dialog and the operator can act on the difference.
 *
 * "Reduce everything" is deliberately not a case this Action serves: a
 * registration that buys nothing is a cancellation, and `CancelRegistration`
 * already releases the quota, revokes the admissions and cancels the order.
 */
final class QuantityReductionRefusedException extends RuntimeException
{
    public static function notConfirmed(string $registrationId): self
    {
        return new self(sprintf(
            'Registration [%s] is not confirmed; only a confirmed registration has units to give back.',
            $registrationId,
        ));
    }

    public static function notPositive(): self
    {
        return new self('Reduce the registration by at least one unit.');
    }

    public static function wholeRegistration(int $units, int $quantity): self
    {
        return new self(sprintf(
            'Removing %d of %d units leaves nothing — cancel the registration instead.',
            $units,
            $quantity,
        ));
    }
}
