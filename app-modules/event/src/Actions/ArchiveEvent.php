<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventArchived;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Closes an event for all operations (SPEC.md "Event lifecycle").
 *
 * Legal from `Concluded` or `Cancelled` — an event is only archived once it
 * is definitively over, one way or the other. Controllers never write
 * `state` or `archived_at` directly.
 */
final class ArchiveEvent
{
    /**
     * @var list<EventLifecycleState>
     */
    private const array LEGAL_FROM_STATES = [
        EventLifecycleState::Concluded,
        EventLifecycleState::Cancelled,
    ];

    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $this->guardTransition($event);

            $event->forceFill([
                'state' => EventLifecycleState::Archived,
                'archived_at' => now(),
            ])->save();

            event(new EventArchived(
                eventId: $event->id,
                brandId: $event->brand_id,
                archivedAt: $event->archived_at->toIso8601String(),
            ));

            return $event->refresh();
        });
    }

    private function guardTransition(Event $event): void
    {
        if (! in_array($event->state, self::LEGAL_FROM_STATES, true)) {
            throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Archived);
        }
    }
}
