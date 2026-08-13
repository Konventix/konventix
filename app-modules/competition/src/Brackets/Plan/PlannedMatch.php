<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Plan;

/**
 * One match a format wants created, before anything is persisted.
 *
 * The engine deals in these rather than Eloquent models so a generator can be
 * tested with no database at all — which matters, because bracket correctness
 * is the one thing here worth proving exhaustively, and a proof that needs a
 * migration to run is a proof nobody runs.
 *
 * `entrantIds` is indexed by slot position (1-based); a null means the slot
 * is filled later by the progression graph, which is what a bracket *is*
 * before its earlier rounds resolve.
 */
final readonly class PlannedMatch
{
    /**
     * @param  array<int, string|null>  $entrantIds  slot position => entrant ULID or null
     */
    public function __construct(
        public string $ref,
        public int $round,
        public int $position,
        public array $entrantIds,
        public ?string $bracket = null,
    ) {}
}
