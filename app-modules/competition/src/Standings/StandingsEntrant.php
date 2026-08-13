<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * An entrant as a calculator sees them: an id, and the seed that breaks ties
 * the results themselves cannot.
 *
 * A null seed sorts last. An unseeded entrant has no claim to the better half
 * of a tier over a seeded one.
 */
final readonly class StandingsEntrant
{
    public function __construct(
        public string $id,
        public ?int $seed = null,
    ) {}

    public function seedOrLast(): int
    {
        return $this->seed ?? PHP_INT_MAX;
    }
}
