<?php

declare(strict_types=1);

namespace Konventix\Resources\Exceptions;

use RuntimeException;

/**
 * Thrown when a resource cannot be booked for the requested interval.
 *
 * Both causes look the same to a caller but come from different mechanisms
 * (ADR-0020, ADR-0034): unit mode is stopped by the database's exclusion
 * constraint, quantity mode by the summed guard inside the booking
 * transaction. Naming them apart keeps that difference visible in tests and
 * logs, where "the constraint fired" and "we counted" are worth telling apart.
 */
final class ResourceUnavailableException extends RuntimeException
{
    public static function alreadyBooked(string $resourceId): self
    {
        return new self(sprintf(
            'Resource [%s] is already booked for part of the requested interval.',
            $resourceId,
        ));
    }

    public static function insufficientCapacity(string $resourceId, int $requested, int $remaining): self
    {
        return new self(sprintf(
            'Resource [%s] has only %d unit(s) free for the requested interval; %d were asked for.',
            $resourceId,
            $remaining,
            $requested,
        ));
    }
}
