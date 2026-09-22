<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Competition\Data\LeaderboardSettings;
use Konventix\Competition\Enums\StageEntrySource;
use Konventix\Competition\Enums\StageFormat;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\Stage;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    /** @var class-string<Stage> */
    protected $model = Stage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'name' => 'Playoffs',
            'format' => 'single_elimination',
            'sequence' => 1,
            'entry_source' => StageEntrySource::Signup,
            'advance_count' => null,
            'settings' => null,
            'generated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function format(string $format, array $settings = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'format' => $format,
            'settings' => $settings === [] ? null : $settings,
        ]);
    }

    /**
     * A leaderboard stage with a typed ruleset (design §7.2) — the settings
     * go in under the same keys `LeaderboardSettings::ofStageSettings()`
     * reads back, so a test never hand-writes the JSON shape.
     */
    public function leaderboard(?LeaderboardSettings $settings = null): static
    {
        return $this->format(
            StageFormat::Leaderboard->value,
            ($settings ?? new LeaderboardSettings)->toStageSettings(),
        );
    }

    /**
     * §7.3's multi-stage model: where the field comes from, and how many
     * entrants the next stage takes from this one.
     */
    public function entrySource(StageEntrySource $entrySource, ?int $advanceCount = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'entry_source' => $entrySource,
            'advance_count' => $advanceCount,
        ]);
    }
}
