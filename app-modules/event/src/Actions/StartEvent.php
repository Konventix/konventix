<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventStarted;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Opens the doors: Published → Running (SPEC.md "Event lifecycle"). An
 * explicit operator decision, not clock-derived (see EventStarted's
 * docblock) — and the missing link that made `Running` legally reachable
 * through the Action layer at all (TODO.md).
 */
final class StartEvent
{
    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            if ($event->state !== EventLifecycleState::Published) {
                throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Running);
            }

            $event->forceFill(['state' => EventLifecycleState::Running])->save();

            event(new EventStarted(
                eventId: $event->id,
                brandId: $event->brand_id,
                startedAt: now()->toIso8601String(),
            ));

            return $event->refresh();
        });
    }
}
