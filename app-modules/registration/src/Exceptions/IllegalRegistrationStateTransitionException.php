<?php

declare(strict_types=1);

namespace Konventix\Registration\Exceptions;

use Konventix\Registration\Enums\RegistrationStatus;
use Konventix\Registration\Models\Registration;
use RuntimeException;

/**
 * Thrown by a transition Action (`src/Actions/`) when the registration's
 * current status is not a legal starting point for the requested transition.
 */
final class IllegalRegistrationStateTransitionException extends RuntimeException
{
    public static function forTransition(Registration $registration, RegistrationStatus $target): self
    {
        return new self(sprintf(
            'Registration [%s] cannot transition from [%s] to [%s].',
            $registration->id,
            $registration->status->value,
            $target->value,
        ));
    }

    public static function forAction(Registration $registration, string $action): self
    {
        return new self(sprintf(
            'Registration [%s] cannot be %s while in status [%s].',
            $registration->id,
            $action,
            $registration->status->value,
        ));
    }
}
