<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * One finished match, reduced to what a standings calculator needs.
 *
 * A calculator never sees an Eloquent model. That is what lets every ranking
 * rule be proven in memory, the same split ADR-0019's generators use: pure
 * functions in `Standings/`, one writer in `Actions/`.
 *
 * `sides` is a list rather than a home/away pair because `MatchSlot` already
 * supports more than two sides — a race heat, a free-for-all. Today's
 * calculators are all head-to-head and skip anything that is not exactly two,
 * which is a stated limitation rather than an assumption baked into the shape.
 */
final readonly class MatchOutcome
{
    /**
     * @param  list<MatchSide>  $sides
     * @param  string|null  $winnerEntrantId  null means a draw, where the format allows one
     */
    public function __construct(
        public int $round,
        public array $sides,
        public ?string $bracket = null,
        public ?string $winnerEntrantId = null,
    ) {}

    public function isHeadToHead(): bool
    {
        return count($this->sides) === 2;
    }

    /**
     * The side that did not win, when exactly one did.
     */
    public function loserEntrantId(): ?string
    {
        if ($this->winnerEntrantId === null || ! $this->isHeadToHead()) {
            return null;
        }

        foreach ($this->sides as $side) {
            if ($side->entrantId !== $this->winnerEntrantId) {
                return $side->entrantId;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function entrantIds(): array
    {
        return array_map(static fn (MatchSide $side): string => $side->entrantId, $this->sides);
    }
}
