<?php

declare(strict_types=1);

namespace Konventix\Competition\Standings;

use RuntimeException;

/**
 * Resolves a stage's `format` to its standings calculator.
 *
 * Deliberately separate from `FormatRegistry` even though the keys match: a
 * format that generates nothing can still rank (a manually-built ladder), and
 * a format that generates can be ranked more than one way. Folding them into
 * one registry would tie the two together for no gain beyond one fewer class.
 */
final class StandingsCalculatorRegistry
{
    /** @var array<string, StandingsCalculator> */
    private array $calculators = [];

    public function register(StandingsCalculator $calculator): void
    {
        $this->calculators[$calculator->key()] = $calculator;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->calculators);
    }

    public function has(string $format): bool
    {
        return isset($this->calculators[$format]);
    }

    public function get(string $format): StandingsCalculator
    {
        return $this->calculators[$format] ?? throw new RuntimeException(sprintf(
            'No standings calculator is registered for [%s]. Registered: %s.',
            $format,
            implode(', ', $this->keys()) ?: 'none',
        ));
    }
}
