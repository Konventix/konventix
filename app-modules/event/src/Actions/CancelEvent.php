<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventCancelled;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Marks that an event will not take place (SPEC.md "Event lifecycle").
 * Cancellation is a first-class transition with a reason, not a soft delete.
 *
 * Legal from any state before the event has concluded: `Draft`, `Announced`,
 * `Published`, `Running`. Controllers never write `state`, `reason_code` or
 * `cancelled_at` directly.
 */
final class CancelEvent
{
    /**
     * @var list<EventLifecycleState>
     */
    private const array LEGAL_FROM_STATES = [
        EventLifecycleState::Draft,
        EventLifecycleState::Announced,
        EventLifecycleState::Published,
        EventLifecycleState::Running,
    ];

    public function handle(Event $event, string $reasonCode): Event
    {
        return DB::transaction(function () use ($event, $reasonCode): Event {
            $this->guardTransition($event);

            $event->forceFill([
                'state' => EventLifecycleState::Cancelled,
                'reason_code' => $reasonCode,
                'cancelled_at' => now(),
            ])->save();

            event(new EventCancelled(
                eventId: $event->id,
                brandId: $event->brand_id,
                reasonCode: $reasonCode,
                cancelledAt: $event->cancelled_at->toIso8601String(),
            ));

            return $event->refresh();
        });
    }

    private function guardTransition(Event $event): void
    {
        if (! in_array($event->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Cancelled);
        }
    }
}
