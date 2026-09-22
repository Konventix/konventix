<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

/**
 * One submitted score, reduced to what the leaderboard calculator needs.
 *
 * The leaderboard's answer to `MatchOutcome`, and it exists for the same
 * reason: a calculator never sees an Eloquent model, so every ranking rule is
 * provable in memory rather than against fixtures.
 *
 * `verified` travels with the value rather than being filtered out by the
 * caller. The rule "only verified entries count" (design §8.3) is a ranking
 * rule, and a ranking rule belongs where the ranking is proved — a calculator
 * handed pre-filtered rows could not fail the test that says an unverified
 * entry changes nothing.
 */
final readonly class ScoredEntry
{
    public function __construct(
        public string $entrantId,
        public float $value,
        public ?int $heat = null,
        public bool $verified = false,
    ) {}
}
