<?php

declare(strict_types=1);

namespace Konventix\Event\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Event\Enums\EventLifecycleState;
use Konventix\Event\Events\EventAnnounced;
use Konventix\Event\Exceptions\IllegalEventStateTransitionException;
use Konventix\Event\Models\Event;

/**
 * Teases an event: Draft → Announced (SPEC.md "Event lifecycle"). The only
 * path to `Announced` — controllers never write `state` directly.
 */
final class AnnounceEvent
{
    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            if ($event->state !== EventLifecycleState::Draft) {
                throw IllegalEventStateTransitionException::forTransition($event, EventLifecycleState::Announced);
            }

            $event->forceFill(['state' => EventLifecycleState::Announced])->save();

            event(new EventAnnounced(
                eventId: $event->id,
                brandId: $event->brand_id,
                announcedAt: now()->toIso8601String(),
            ));

            return $event->refresh();
        });
    }
}
