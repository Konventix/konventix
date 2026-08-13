<?php

declare(strict_types=1);

namespace Konventix\Competition\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Konventix\Competition\Enums\EntrantState;
use Konventix\Competition\Models\Competition;
use Konventix\Competition\Models\CompetitionTeam;
use Konventix\Competition\Models\Entrant;

/**
 * @extends Factory<Entrant>
 */
class EntrantFactory extends Factory
{
    /** @var class-string<Entrant> */
    protected $model = Entrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'competition_team_id' => CompetitionTeam::factory(),
            'state' => EntrantState::Registered,
            'seed' => null,
            'checked_in_at' => null,
        ];
    }
}
