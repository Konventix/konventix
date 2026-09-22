<?php

declare(strict_types=1);

namespace Konventix\Event\Events;

/**
 * The operator saved the live hub's layout for an event (design §6).
 * Carries the complete layout, not a diff, so Platform's projection
 * replaces its row rather than patching it — the same contract
 * `EventDaysChanged` keeps with Admission. Keys and scalars only
 * (ADR-0004): Platform never sees Event's model.
 */
final readonly class EventHubLayoutChanged
{
    /**
     * @param  list<array{key: string, visible: bool}>  $cards  display order, every registry key the operator saw
     * @param  bool  $hubReplacesHome  signed-in, admitted members land on the hub instead of the home page
     */
    public function __construct(
        public string $eventId,
        public string $brandId,
        public array $cards,
        public bool $hubReplacesHome,
    ) {}
}
