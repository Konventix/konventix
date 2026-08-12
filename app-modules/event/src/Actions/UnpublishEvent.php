<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventUnpublished;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Withdraws an event from public view (SPEC.md "Event lifecycle").
 *
 * Legal from `Published` only, returning the event to `Announced` — the
 * event's own progress is not discarded back to `Draft`. Controllers never
 * write `state` or `published_at` directly.
 */
final class UnpublishEvent
{
    /**
     * @var list<EventLifecycleState>
     */
    private const array LEGAL_FROM_STATES = [
        EventLifecycleState::Published,
    ];

    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $this->guardTransition($event);

            $event->forceFill([
                'state' => EventLifecycleState::Announced,
                'published_at' => null,
            ])->save();

            event(new EventUnpublished(
                eventId: $event->id,
                brandId: $event->brand_id,
            ));

            return $event->refresh();
        });
    }

    private function guardTransition(Event $event): void
    {
        if (! in_array($event->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Announced);
        }
    }
}
