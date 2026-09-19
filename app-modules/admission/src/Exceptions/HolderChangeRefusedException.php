<?php

declare(strict_types=1);

namespace Konventix\Admission\Exceptions;

use RuntimeException;

/**
 * Thrown by `AssignHolder` and `UnassignHolder` when the change cannot be
 * made (#292, #16). Named constructors for the audit trail and the tests;
 * the controllers turn every one of them into a plain validation message
 * on the ticket manager, since the person asking is the buyer and may know
 * exactly why.
 */
final class HolderChangeRefusedException extends RuntimeException
{
    public static function alreadyHeld(): self
    {
        return new self('This ticket already has a holder.');
    }

    public static function notHeld(): self
    {
        return new self('This ticket has no holder to take back.');
    }

    public static function notInAssignableState(): self
    {
        return new self('This ticket is no longer valid.');
    }

    public static function checkedIn(): self
    {
        return new self('This ticket has already been used at the door and cannot be taken back.');
    }
}
