<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets\Plan;

/**
 * The complete shape a format wants: every match and every edge between them.
 *
 * A format generator returns one of these and writes nothing. Persisting is
 * an Action's job, which keeps the engine pure, keeps the transaction in one
 * place, and lets every correctness property be asserted against a plan in
 * memory.
 */
final readonly class BracketPlan
{
    /**
     * @param  list<PlannedMatch>  $matches
     * @param  list<PlannedConnection>  $connections
     * @param  list<string>  $byeEntrantIds  entrants advanced without playing
     */
    public function __construct(
        public array $matches = [],
        public array $connections = [],
        public array $byeEntrantIds = [],
    ) {}

    public function matchCount(): int
    {
        return count($this->matches);
    }

    public function rounds(): int
    {
        return $this->matches === [] ? 0 : max(array_map(
            fn (PlannedMatch $match): int => $match->round,
            $this->matches,
        ));
    }

    /**
     * @return list<PlannedMatch>
     */
    public function matchesInRound(int $round): array
    {
        return array_values(array_filter(
            $this->matches,
            fn (PlannedMatch $match): bool => $match->round === $round,
        ));
    }
}
