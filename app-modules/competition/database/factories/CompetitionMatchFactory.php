<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Competition\Enums\MatchState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionMatch;

/**
 * @extends Factory<CompetitionMatch>
 */
class CompetitionMatchFactory extends Factory
{
    /** @var class-string<CompetitionMatch> */
    protected $model = CompetitionMatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'round' => 1,
            'position' => fake()->unique()->numberBetween(1, 9999),
            'state' => MatchState::Pending,
            'winner_entrant_id' => null,
            'scheduled_at' => null,
            'finished_at' => null,
            'finished_event_emitted_at' => null,
        ];
    }
}
