<?php

declare(strict_types=1);

namespace Konventix\Competition\Seeding;

use Konventix\Competition\Enums\StageEntrySource;
use RuntimeException;

/**
 * Resolves a stage's `entry_source` to the source that fills it (design
 * §7.3).
 *
 * The seam itself. `PreviousStageSeedSource` is the implementation this
 * exists for and the one this slice does not build: adding it later is one
 * `register()` call in `CompetitionServiceProvider`, and no caller changes —
 * `SeedStage` already asks this registry, and already passes nothing but the
 * stage.
 *
 * Until then an unregistered source **refuses by name**. The alternative — a
 * null source, or falling back to signup — would seed the second stage of a
 * qualifier with everybody who ever entered, and the bracket would look
 * perfectly plausible.
 *
 * Shaped after `FormatRegistry`, and separate from it for the same reason
 * that registry is separate from `StandingsCalculatorRegistry`: a stage's
 * format and the provenance of its field are independent questions, and a
 * leaderboard stage seeded from a previous bracket is a combination neither
 * registry should have to know about.
 */
final class SeedSourceRegistry
{
    /** @var array<string, SeedSource> */
    private array $sources = [];

    public function register(SeedSource $source): void
    {
        $this->sources[$source->entrySource()->value] = $source;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->sources);
    }

    public function has(StageEntrySource $entrySource): bool
    {
        return isset($this->sources[$entrySource->value]);
    }

    public function get(StageEntrySource $entrySource): SeedSource
    {
        return $this->sources[$entrySource->value] ?? throw new RuntimeException(sprintf(
            'No seed source is registered for [%s]. Registered: %s. Multi-stage entry is modelled, not built (design §7.3).',
            $entrySource->value,
            implode(', ', $this->keys()) ?: 'none',
        ));
    }
}
