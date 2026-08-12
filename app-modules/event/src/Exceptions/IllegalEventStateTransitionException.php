<?php

declare(strict_types=1);

namespace Konventix\Event\Exceptions;

use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Models\Event;
use RuntimeException;

/**
 * Thrown by a lifecycle Action (`src/Actions/`) when the event's current
 * state is not a legal starting point for the requested transition.
 */
final class IllegalEventStateTransitionException extends RuntimeException
{
    public static function forTransition(Event $event, EventLifecycleState $target): self
    {
        return new self(sprintf(
            'Event [%s] cannot transition from [%s] to [%s].',
            $event->id,
            $event->state->value,
            $target->value,
        ));
    }

    /**
     * For Actions that guard a state without transitioning to a single named
     * target — RescheduleEvent legality does not itself change `state`.
     */
    public static function forAction(Event $event, string $action): self
    {
        return new self(sprintf(
            'Event [%s] cannot be %s while in state [%s].',
            $event->id,
            $action,
            $event->state->value,
        ));
    }
}
