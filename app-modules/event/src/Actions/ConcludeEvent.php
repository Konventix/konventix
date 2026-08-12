<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventConcluded;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Marks that an event has ended (SPEC.md "Event lifecycle"). This is what
 * starts retention clocks in Compliance.
 *
 * Legal from `Running` only. There is no `StartEvent` Action yet to move an
 * event into `Running` — see TODO.md follow-ups. Controllers never write
 * `state` or `concluded_at` directly.
 */
final class ConcludeEvent
{
    /**
     * @var list<EventLifecycleState>
     */
    private const array LEGAL_FROM_STATES = [
        EventLifecycleState::Running,
    ];

    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $this->guardTransition($event);

            $event->forceFill([
                'state' => EventLifecycleState::Concluded,
                'concluded_at' => now(),
            ])->save();

            event(new EventConcluded(
                eventId: $event->id,
                brandId: $event->brand_id,
                concludedAt: $event->concluded_at->toIso8601String(),
            ));

            return $event->refresh();
        });
    }

    private function guardTransition(Event $event): void
    {
        if (! in_array($event->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Concluded);
        }
    }
}
