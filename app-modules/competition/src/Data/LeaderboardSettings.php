<?php

declare(strict_types=1);

namespace Konventix\Competition\Data;

use Konventix\Competition\Enums\LeaderboardAggregate;
use Konventix\Competition\Enums\LeaderboardDirection;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * A leaderboard stage's ruleset (design §7.2, ADR-0009).
 *
 * A typed object, never a settings blob: the five fields below are the whole
 * vocabulary, and a stage carrying anything else is carrying settings for a
 * format it was switched away from. That is why `ofStageSettings()` reads
 * key by key with a default each rather than hydrating the array wholesale —
 * the same forgiveness `FormatGenerator::defaults()` gives a bracket stage,
 * for the same reason: a switched format must not make a stage unreadable.
 *
 * `unit` and `decimals` are **display** settings and nothing else. The
 * ranking compares the aggregates exactly, so two lap times that print
 * identically at two decimals can still be a place apart — which is how a
 * photo finish works, and the alternative (rounding before comparing) would
 * silently invent a tie the stopwatch did not record.
 *
 * Stored in `competition_stages.settings` under the same snake_case keys the
 * bracket formats use (`points_win`, `best_of`), so every one of these five
 * is a single word and the two casings coincide.
 */
final class LeaderboardSettings extends Data
{
    public const string DEFAULT_UNIT = 'Punkte';

    public function __construct(
        #[Required]
        public readonly LeaderboardDirection $direction = LeaderboardDirection::Higher,

        #[Required]
        public readonly LeaderboardAggregate $aggregate = LeaderboardAggregate::Best,

        /**
         * `null` is one resubmittable entry per entrant (Trackmania); `n` is
         * heats numbered 1..n (Fall Guys' five). Not "unlimited entries" —
         * the unique index is `(stage_id, entrant_id, heat)` either way.
         */
        #[Nullable, IntegerType, Min(1), Max(64)]
        public readonly ?int $heats = null,

        /**
         * Shown to participants beside every value, so the default is German
         * (ADR-0001; final review, part 3, M6): a stage saved before this
         * keeps the `pts` it stored.
         */
        #[Required, StringType, Max(8)]
        public readonly string $unit = self::DEFAULT_UNIT,

        #[Required, IntegerType, Min(0), Max(3)]
        public readonly int $decimals = 0,
    ) {}

    /**
     * Read a stage's `settings` column.
     *
     * Named `of*` rather than `from*`: laravel-data treats **any** public
     * static `from*` method as a magic creation method and would route the
     * Eloquent cast through this one by signature (.ai/rules/data.md).
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function ofStageSettings(?array $settings): self
    {
        $settings ??= [];

        $direction = $settings['direction'] ?? null;
        $aggregate = $settings['aggregate'] ?? null;
        $heats = $settings['heats'] ?? null;

        return new self(
            direction: (is_string($direction) ? LeaderboardDirection::tryFrom($direction) : null)
                ?? LeaderboardDirection::Higher,
            aggregate: (is_string($aggregate) ? LeaderboardAggregate::tryFrom($aggregate) : null)
                ?? LeaderboardAggregate::Best,
            heats: is_numeric($heats) ? max(1, (int) $heats) : null,
            unit: is_string($settings['unit'] ?? null) ? $settings['unit'] : self::DEFAULT_UNIT,
            decimals: is_numeric($settings['decimals'] ?? null) ? min(3, max(0, (int) $settings['decimals'])) : 0,
        );
    }

    /**
     * The shape this writes back into `competition_stages.settings`.
     *
     * @return array<string, mixed>
     */
    public function toStageSettings(): array
    {
        return [
            'direction' => $this->direction->value,
            'aggregate' => $this->aggregate->value,
            'heats' => $this->heats,
            'unit' => $this->unit,
            'decimals' => $this->decimals,
        ];
    }

    /**
     * Whether a heat number is one this stage actually has.
     *
     * The two shapes are different questions, not one with a default: a
     * stage without heats accepts exactly one entry per entrant and its heat
     * column is null, and a stage with five accepts 1..5 and nothing else.
     * A null heat on a five-heat stage is not "the first heat".
     */
    public function acceptsHeat(?int $heat): bool
    {
        if ($this->heats === null) {
            return $heat === null;
        }

        return $heat !== null && $heat >= 1 && $heat <= $this->heats;
    }
}
