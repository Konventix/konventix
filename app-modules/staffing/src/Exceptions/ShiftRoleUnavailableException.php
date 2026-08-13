<?php

declare(strict_types=1);

namespace Konventix\Staffing\Exceptions;

use RuntimeException;

/**
 * Thrown when a shift role cannot be claimed. Named causes because a
 * volunteer told "you cannot sign up" deserves to know whether the shift is
 * full, unpublished, or already theirs.
 */
final class ShiftRoleUnavailableException extends RuntimeException
{
    public static function notPublished(string $shiftId): self
    {
        return new self(sprintf('Shift [%s] is not published; nobody can sign up for it yet.', $shiftId));
    }

    public static function full(string $shiftRoleId, int $required): self
    {
        return new self(sprintf('Shift role [%s] already has its %d person(s).', $shiftRoleId, $required));
    }

    public static function alreadySignedUp(string $shiftRoleId, string $userId): self
    {
        return new self(sprintf('User [%s] is already signed up for shift role [%s].', $userId, $shiftRoleId));
    }
}
