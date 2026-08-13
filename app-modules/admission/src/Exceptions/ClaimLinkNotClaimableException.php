<?php

declare(strict_types=1);

namespace Konventix\Admission\Exceptions;

use RuntimeException;

/**
 * Thrown by `ClaimAdmission` when a link cannot be redeemed (ADR-0032).
 *
 * The named constructors distinguish causes for the audit trail and for
 * tests, but every one of them is surfaced to the claimant as the same 410
 * by `ClaimController`. Telling whoever holds a forwarded link *why* it
 * failed — "already claimed" versus "not your address" versus "no such link"
 * — leaks who else is on the booking, and none of the distinctions help
 * someone who legitimately cannot use the link anyway.
 */
final class ClaimLinkNotClaimableException extends RuntimeException
{
    public static function unknown(): self
    {
        return new self('No such claim link.');
    }

    public static function alreadySettled(): self
    {
        return new self('This claim link has already been used, was revoked, or has expired.');
    }

    public static function wrongRecipient(): self
    {
        return new self('This claim link was issued to a different address.');
    }

    public static function alreadyHeld(): self
    {
        return new self('This admission already has a holder.');
    }

    public static function admissionNotClaimable(): self
    {
        return new self('This admission is no longer in a claimable state.');
    }
}
