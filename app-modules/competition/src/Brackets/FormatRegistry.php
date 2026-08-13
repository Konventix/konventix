<?php

declare(strict_types=1);

namespace Konventix\Competition\Brackets;

use RuntimeException;

/**
 * Resolves a stage's `format` string to its generator (ADR-0019).
 *
 * A registry rather than a match expression so a sixth format is a
 * registration rather than an edit to a switch nobody remembers to update —
 * and so `race_heat` and `final_stage`, which LanBrackets carried as null
 * stubs, can arrive without touching anything that already works.
 */
final class FormatRegistry
{
    /** @var array<string, FormatGenerator> */
    private array $generators = [];

    public function register(FormatGenerator $generator): void
    {
        $this->generators[$generator->key()] = $generator;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->generators);
    }

    public function has(string $format): bool
    {
        return isset($this->generators[$format]);
    }

    public function get(string $format): FormatGenerator
    {
        return $this->generators[$format] ?? throw new RuntimeException(sprintf(
            'No bracket format is registered for [%s]. Registered: %s.',
            $format,
            implode(', ', $this->keys()) ?: 'none',
        ));
    }
}
