<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventPublished;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Makes an event publicly visible (SPEC.md "Event lifecycle").
 *
 * Legal from `Draft` or `Announced`. Controllers never write `state` or
 * `published_at` directly — this is the only path to `Published`.
 */
final class PublishEvent
{
    /**
     * @var list<EventLifecycleState>
     */
    private const array LEGAL_FROM_STATES = [
        EventLifecycleState::Draft,
        EventLifecycleState::Announced,
    ];

    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $this->guardTransition($event);

            $event->forceFill([
                'state' => EventLifecycleState::Published,
                'published_at' => now(),
            ])->save();

            event(new EventPublished(
                eventId: $event->id,
                brandId: $event->brand_id,
                publishedAt: $event->published_at->toIso8601String(),
            ));

            return $event->refresh();
        });
    }

    private function guardTransition(Event $event): void
    {
        if (! in_array($event->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Published);
        }
    }
}
