<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventRescheduled;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Changes an event's start, end or timezone (SPEC.md "Event lifecycle").
 *
 * Legal before the event is running: `Draft`, `Announced`, `Published`.
 * `startsAt`/`endsAt` are wall-clock strings (`Y-m-d H:i:s`) — stored
 * verbatim, never reinterpreted (ADR-0027). Controllers never write
 * `starts_at`/`ends_at`/`timezone` directly.
 */
final class RescheduleEvent
{
    /**
     * @var list<EventLifecycleState>
     */
    private const array LEGAL_FROM_STATES = [
        EventLifecycleState::Draft,
        EventLifecycleState::Announced,
        EventLifecycleState::Published,
    ];

    public function handle(Event $event, string $startsAt, string $endsAt, string $timezone): Event
    {
        return DB::transaction(function () use ($event, $startsAt, $endsAt, $timezone): Event {
            $this->guardTransition($event);

            if ($endsAt <= $startsAt) {
                throw new InvalidArgumentException('An event cannot end before it starts.');
            }

            $event->forceFill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
            ])->save();

            event(new EventRescheduled(
                eventId: $event->id,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $timezone,
                brandId: $event->brand_id,
            ));

            return $event->refresh();
        });
    }

    private function guardTransition(Event $event): void
    {
        if (! in_array($event->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalEventStateTransitionException::forAction($event, 'rescheduled');
        }
    }
}
