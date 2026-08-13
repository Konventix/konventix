<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Plan;

/**
 * One edge of the progression graph, in plan form: which outcome of which
 * planned match feeds which slot of another.
 *
 * References planned matches by their `ref` rather than by id, because none
 * of them have ids yet — the whole point of planning before persisting.
 */
final readonly class PlannedConnection
{
    public function __construct(
        public string $sourceRef,
        public string $targetRef,
        public string $sourceOutcome,
        public int $targetSlot,
    ) {}
}
